import { existsSync, readdirSync, readFileSync, realpathSync, statSync } from 'node:fs';
import { homedir, hostname } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { ApiClient, ApiError } from '../api.ts';
import { findProjectConfig, parseProjectConfig, PROJECT_FILE, projectTokens, readToken } from '../config.ts';
import { UsageError, type Context } from '../context.ts';
import { openInTerminal, printOnly, type Launcher } from '../launch.ts';

export interface RunnerOptions {
  once?: boolean;
  interval?: number;
  claude?: string;
  print?: boolean;
  project?: string;
}

export interface RunnerDeps {
  launch?: Launcher;
  sleep?: (ms: number) => Promise<void>;
  /** Stops the loop (tests); the CLI runs until Ctrl+C. */
  shouldStop?: () => boolean;
}

interface ClaimedRun {
  id: number;
  project_id: number;
  title: string;
  prompt: string;
  local_path: string | null;
  requested_by: string | null;
}

interface Watch {
  apiUrl: string;
  projectId: number;
  client: ApiClient;
  label: string;
  /** The linked repository the runner was started in, used when Unseen has no project folder set yet. */
  folder?: string;
  stopped?: boolean;
}

/**
 * `unseen runner`: waits for "Proceed" clicks in Unseen and opens Claude Code with the prompt in the
 * project's folder. Watches every project this computer has a token for (from "unseen init"), plus the
 * repository it is started in. It only launches in a folder linked to that same project and server.
 */
export async function runner(opts: RunnerOptions, ctx: Context, deps: RunnerDeps = {}): Promise<number> {
  const interval = Math.max(1, opts.interval ?? 3) * 1000;
  const sleep = deps.sleep ?? ((ms) => new Promise((r) => setTimeout(r, ms)));
  const launch = deps.launch ?? (opts.print ? printOnly(ctx.out) : openInTerminal);
  const name = `${hostname()}`.slice(0, 100);
  const claude = opts.claude ?? 'claude';

  const watches = new Map<string, Watch>();
  const add = (apiUrl: string, projectId: number, token: string) => {
    const key = `${apiUrl}#${projectId}`;
    if (!watches.has(key)) watches.set(key, { apiUrl, projectId, client: new ApiClient(apiUrl, token, ctx.fetch), label: `project ${projectId} on ${apiUrl}` });
  };
  for (const t of projectTokens(ctx.env)) add(t.apiUrl, t.projectId, t.token);
  const here = findProjectConfig(ctx.cwd);
  if (here) {
    const { token } = readToken(here.config.apiUrl, ctx.env, here.config.projectId);
    if (token) add(here.config.apiUrl, here.config.projectId, token);
    const watch = watches.get(`${here.config.apiUrl}#${here.config.projectId}`);
    if (watch) watch.folder = realpathSync(dirname(here.path));
  }
  if (opts.project) {
    for (const [key, w] of watches) if (String(w.projectId) !== opts.project) watches.delete(key);
  }
  if (watches.size === 0) {
    throw new UsageError('No linked projects on this computer. Link a repository first (the setup prompt from Unseen, or "unseen init").');
  }

  ctx.out(`Unseen runner on ${name}: watching ${[...watches.values()].map((w) => w.label).join(', ')}.`);
  ctx.out('Proceed buttons in Unseen now open Claude Code here. Press Ctrl+C to stop.');

  do {
    for (const watch of watches.values()) {
      if (watch.stopped) continue;
      try {
        const run = await watch.client.request<{ data: ClaimedRun } | null>('POST', `/projects/${watch.projectId}/runs/claim`, {
          runner: name,
          ...(watch.folder ? { folder: watch.folder } : {}),
        });
        if (run?.data) await handle(run.data, watch, { launch, claude, ctx });
      } catch (error) {
        if (error instanceof ApiError && (error.status === 401 || error.status === 403)) {
          watch.stopped = true;
          ctx.err(`Stopped watching ${watch.label}: ${error.message}`);
        } else {
          ctx.err(`Couldn't reach ${watch.label}: ${(error as Error).message}`);
        }
      }
    }
    if ([...watches.values()].every((w) => w.stopped)) return 1;
    if (opts.once || deps.shouldStop?.()) break;
    await sleep(interval);
  } while (!deps.shouldStop?.());

  return 0;
}

async function handle(run: ClaimedRun, watch: Watch, io: { launch: Launcher; claude: string; ctx: Context }) {
  const report = (status: 'launched' | 'failed', error?: string) =>
    watch.client.request('PATCH', `/runs/${run.id}`, { status, ...(error ? { error } : {}) });

  io.ctx.out(`→ ${run.title}${run.requested_by ? ` (from ${run.requested_by})` : ''}`);
  const folder = checkFolder(run.local_path ?? watch.folder ?? null, watch);
  if ('error' in folder) {
    io.ctx.err(`  Not opened: ${folder.error}`);
    await report('failed', folder.error);
    return;
  }

  const result = await io.launch({ folder: folder.path, prompt: run.prompt, title: run.title, claude: io.claude });
  if (result.ok) {
    io.ctx.out(`  Opened Claude Code in ${folder.path} (${result.how}).`);
    await report('launched');
  } else {
    io.ctx.err(`  Not opened: ${result.error}`);
    await report('failed', result.error);
  }
}

/** The run's folder must exist and be linked (.unseen.json) to the same project on the same server. */
function checkFolder(localPath: string | null, watch: Watch): { path: string } | { error: string } {
  if (!localPath) return { error: 'No project folder is set in Unseen (project Settings), and the runner was not started in the linked repository.' };
  const expanded = localPath.startsWith('~/') ? join(homedir(), localPath.slice(2)) : localPath;
  const path = resolve(expanded);
  if (!existsSync(path) || !statSync(path).isDirectory()) return { error: `Folder not found on this computer: ${localPath}` };

  const configPath = join(path, PROJECT_FILE);
  if (!existsSync(configPath)) {
    const nested = findLinkedBelow(path, watch);
    return {
      error: nested
        ? `${localPath} isn't linked to Unseen, but ${nested} is. Set that as the project folder in Unseen (project Settings).`
        : `${localPath} isn't linked to Unseen (no ${PROJECT_FILE}). Run the setup prompt there first.`,
    };
  }
  let config;
  try {
    config = parseProjectConfig(readFileSync(configPath, 'utf8'), configPath);
  } catch (error) {
    return { error: (error as Error).message };
  }
  if (config.projectId !== watch.projectId || config.apiUrl !== watch.apiUrl) {
    return { error: `${localPath} is linked to project ${config.projectId} on ${config.apiUrl}, not this project.` };
  }
  return { path: realpathSync(path) };
}

/** A subfolder (up to two levels down) linked to this project, to suggest when the set folder is its parent. */
function findLinkedBelow(root: string, watch: Watch): string | null {
  const skip = new Set(['node_modules', 'vendor', 'dist', 'build']);
  const walk = (dir: string, depth: number): string | null => {
    let entries: string[];
    try { entries = readdirSync(dir); } catch { return null; }
    for (const entry of entries.sort()) {
      if (skip.has(entry) || entry.startsWith('.')) continue;
      const child = join(dir, entry);
      try { if (!statSync(child).isDirectory()) continue; } catch { continue; }
      const file = join(child, PROJECT_FILE);
      if (existsSync(file)) {
        try {
          const config = parseProjectConfig(readFileSync(file, 'utf8'), file);
          if (config.projectId === watch.projectId && config.apiUrl === watch.apiUrl) return child;
        } catch { /* not ours */ }
      }
      if (depth < 2) {
        const found = walk(child, depth + 1);
        if (found) return found;
      }
    }
    return null;
  };
  return walk(root, 1);
}
