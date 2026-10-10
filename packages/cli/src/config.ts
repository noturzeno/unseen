import { chmodSync, existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, parse } from 'node:path';

/** Repository link. Contains no secrets, so it is safe to commit. */
export interface ProjectConfig {
  apiUrl: string;
  projectId: number;
}

export const PROJECT_FILE = '.unseen.json';

type Env = Record<string, string | undefined>;

export class ConfigError extends Error {}

const LOCAL_HOSTS = new Set(['localhost', '127.0.0.1', '[::1]', '::1']);

/**
 * Normalise and check an API base URL (the Unseen app URL, without /api/v1).
 * Plain http is only allowed for local development hosts, so tokens never cross the network unencrypted.
 */
export function normalizeApiUrl(raw: string): string {
  let url: URL;
  try {
    url = new URL(raw.trim());
  } catch {
    throw new ConfigError(`"${raw}" is not a valid URL.`);
  }
  if (url.protocol !== 'https:' && url.protocol !== 'http:') {
    throw new ConfigError('The Unseen URL must start with https:// (or http:// for a local server).');
  }
  const host = url.hostname.toLowerCase();
  const isLocal = LOCAL_HOSTS.has(host) || host.endsWith('.localhost') || host.endsWith('.test');
  if (url.protocol === 'http:' && !isLocal) {
    throw new ConfigError(`Refusing to send credentials over plain http to ${host}. Use https://.`);
  }
  if (url.username || url.password) {
    throw new ConfigError('Do not put credentials in the Unseen URL.');
  }
  return url.origin + url.pathname.replace(/\/+$/, '').replace(/\/api\/v1$/, '');
}

export function findProjectConfig(cwd: string): { path: string; config: ProjectConfig } | null {
  let dir = cwd;
  const root = parse(dir).root;
  for (;;) {
    const candidate = join(dir, PROJECT_FILE);
    if (existsSync(candidate)) {
      return { path: candidate, config: parseProjectConfig(readFileSync(candidate, 'utf8'), candidate) };
    }
    if (dir === root) return null;
    dir = dirname(dir);
  }
}

export function parseProjectConfig(text: string, source = PROJECT_FILE): ProjectConfig {
  let data: unknown;
  try {
    data = JSON.parse(text);
  } catch {
    throw new ConfigError(`${source} is not valid JSON.`);
  }
  const obj = data as Partial<ProjectConfig> | null;
  if (!obj || typeof obj.apiUrl !== 'string' || !Number.isInteger(obj.projectId) || (obj.projectId as number) <= 0) {
    throw new ConfigError(`${source} must contain "apiUrl" (string) and "projectId" (positive integer).`);
  }
  return { apiUrl: normalizeApiUrl(obj.apiUrl), projectId: obj.projectId as number };
}

export function writeProjectConfig(dir: string, config: ProjectConfig): string {
  const path = join(dir, PROJECT_FILE);
  writeFileSync(path, JSON.stringify(config, null, 2) + '\n');
  return path;
}

export function credentialsPath(env: Env = process.env): string {
  const base = env.UNSEEN_CONFIG_DIR
    ?? (env.XDG_CONFIG_HOME ? join(env.XDG_CONFIG_HOME, 'unseen') : join(homedir(), '.config', 'unseen'));
  return join(base, 'credentials.json');
}

interface CredentialsFile {
  tokens: Record<string, string>;
}

function readCredentials(path: string): CredentialsFile {
  if (!existsSync(path)) return { tokens: {} };
  try {
    const data = JSON.parse(readFileSync(path, 'utf8')) as Partial<CredentialsFile>;
    return { tokens: data.tokens && typeof data.tokens === 'object' ? data.tokens : {} };
  } catch {
    throw new ConfigError(`${path} is not valid JSON. Fix or delete it, then run "unseen init" again.`);
  }
}

export interface TokenLookup {
  token: string | null;
  source: 'env' | 'file' | null;
  warnings: string[];
}

/**
 * Credentials-file key. Each project gets its own entry (tokens from "Connect a coding agent" only work
 * for one project); the bare server URL holds a token not tied to a project (from signing in).
 */
function tokenKey(apiUrl: string, projectId?: number): string {
  return projectId ? `${apiUrl}#project=${projectId}` : apiUrl;
}

/**
 * Token precedence: UNSEEN_TOKEN env var, then this project's token in the per-user credentials
 * file, then the server-wide token there (never the repo).
 */
export function readToken(apiUrl: string, env: Env = process.env, projectId?: number): TokenLookup {
  if (env.UNSEEN_TOKEN) return { token: env.UNSEEN_TOKEN.trim(), source: 'env', warnings: [] };

  const path = credentialsPath(env);
  const warnings: string[] = [];
  if (existsSync(path) && process.platform !== 'win32') {
    const mode = statSync(path).mode & 0o777;
    if (mode & 0o077) {
      warnings.push(`${path} is readable by other users (mode ${mode.toString(8)}). Run: chmod 600 "${path}"`);
    }
  }
  const tokens = readCredentials(path).tokens;
  const token = (projectId ? tokens[tokenKey(apiUrl, projectId)] : undefined) ?? tokens[apiUrl] ?? null;
  return { token, source: token ? 'file' : null, warnings };
}

export function saveToken(apiUrl: string, token: string, env: Env = process.env, projectId?: number): string {
  const path = credentialsPath(env);
  mkdirSync(dirname(path), { recursive: true, mode: 0o700 });
  const data = readCredentials(path);
  data.tokens[tokenKey(apiUrl, projectId)] = token;
  writeFileSync(path, JSON.stringify(data, null, 2) + '\n', { mode: 0o600 });
  if (process.platform !== 'win32') chmodSync(path, 0o600);
  return path;
}

/** The per-project tokens stored on this computer (from "unseen init"), one entry per linked project. */
export function projectTokens(env: Env = process.env): { apiUrl: string; projectId: number; token: string }[] {
  const tokens = readCredentials(credentialsPath(env)).tokens;
  return Object.entries(tokens).flatMap(([key, token]) => {
    const m = /^(.+)#project=(\d+)$/.exec(key);
    return m ? [{ apiUrl: m[1]!, projectId: Number(m[2]), token }] : [];
  });
}
