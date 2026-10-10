import { ApiClient, type Fetch } from './api.ts';
import { ConfigError, findProjectConfig, readToken, type ProjectConfig } from './config.ts';

/** Everything a command touches outside itself; swapped out in tests. */
export interface Context {
  cwd: string;
  env: Record<string, string | undefined>;
  out: (text: string) => void;
  err: (text: string) => void;
  fetch: Fetch;
  interactive: boolean;
  readStdin: () => Promise<string>;
}

export class UsageError extends Error {}

export interface LinkedProject {
  config: ProjectConfig;
  client: ApiClient;
  token: string;
}

/** Loads .unseen.json and the token, or explains how to set them up. */
export function linkedProject(ctx: Context): LinkedProject {
  const found = findProjectConfig(ctx.cwd);
  if (!found) throw new ConfigError('This repository is not linked to Unseen. Run "unseen init" first.');

  const apiUrl = found.config.apiUrl;
  const { token, warnings } = readToken(apiUrl, ctx.env, found.config.projectId);
  warnings.forEach((w) => ctx.err(`Warning: ${w}`));
  if (!token) {
    throw new ConfigError(`No Unseen token for ${apiUrl}. Run "unseen init" or set UNSEEN_TOKEN.`);
  }

  return { config: found.config, client: new ApiClient(apiUrl, token, ctx.fetch), token };
}
