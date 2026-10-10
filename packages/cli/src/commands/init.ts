import { existsSync } from 'node:fs';
import { hostname } from 'node:os';
import { join } from 'node:path';
import { ApiClient, CLI_TOKEN_DAYS } from '../api.ts';
import { ConfigError, normalizeApiUrl, PROJECT_FILE, readToken, saveToken, writeProjectConfig } from '../config.ts';
import { UsageError, type Context } from '../context.ts';
import { ask, askHidden } from '../prompt.ts';

export interface InitOptions {
  apiUrl?: string;
  project?: string;
  force?: boolean;
  /** Read a token (e.g. from the web app's "Connect a coding agent" prompt) from stdin and save it. */
  tokenStdin?: boolean;
}

/**
 * Links the current directory to an Unseen project:
 *  1. resolve the Unseen URL
 *  2. find a token (env / credentials file) or sign in to create an agent token (read, report, record; never verify)
 *  3. pick a project the user can access
 *  4. write .unseen.json (no secrets)
 */
export async function init(opts: InitOptions, ctx: Context): Promise<number> {
  if (existsSync(join(ctx.cwd, PROJECT_FILE)) && !opts.force) {
    throw new UsageError(`${PROJECT_FILE} already exists here. Use --force to relink.`);
  }

  const rawUrl = opts.apiUrl ?? ctx.env.UNSEEN_API_URL
    ?? (ctx.interactive ? await ask('Unseen URL', 'https://onez-unseen.vercel.app') : undefined);
  if (!rawUrl) throw new UsageError('Pass --api-url (or set UNSEEN_API_URL) when not running interactively.');
  const apiUrl = normalizeApiUrl(rawUrl);

  let projectId = opts.project ? Number(opts.project) : NaN;
  if (opts.project && (!Number.isInteger(projectId) || projectId <= 0)) {
    throw new UsageError('--project must be a project id (a positive number).');
  }
  if (opts.tokenStdin && !opts.project) throw new UsageError('--token-stdin needs --project <id>: the token belongs to one project.');

  let { token, source, warnings } = readToken(apiUrl, ctx.env, opts.project ? projectId : undefined);
  warnings.forEach((w) => ctx.err(`Warning: ${w}`));

  if (opts.tokenStdin) {
    const given = (await ctx.readStdin()).trim();
    if (!/^\d+\|[A-Za-z0-9]{20,}$/.test(given)) throw new UsageError('--token-stdin expects one Unseen API token on stdin.');
    await new ApiClient(apiUrl, given, ctx.fetch).project(projectId); // must work for this project before it is saved
    token = given;
    // Saved under this project, so linking another project on this computer never replaces it.
    const saved = saveToken(apiUrl, token, ctx.env, projectId);
    source = 'file';
    ctx.out(`Token saved to ${saved} (readable only by you).`);
  } else if (!token) {
    if (!ctx.interactive) {
      throw new ConfigError('No token found. Set UNSEEN_TOKEN, or run "unseen init" in a terminal to sign in.');
    }
    ctx.out(`Sign in to ${apiUrl} to create an agent token (record features, reports and scans; cannot verify; expires in ${CLI_TOKEN_DAYS} days).`);
    const email = await ask('Email');
    const password = await askHidden('Password');
    token = await new ApiClient(apiUrl, null, ctx.fetch).createToken(email, password, `unseen-cli@${hostname()}`);
    const path = saveToken(apiUrl, token, ctx.env);
    source = 'file';
    ctx.out(`Token saved to ${path} (readable only by you).`);
  }

  const client = new ApiClient(apiUrl, token, ctx.fetch);
  const me = await client.me();

  if (!opts.project) {
    if (!ctx.interactive) throw new UsageError('Pass --project <id> when not running interactively.');
    const projects = await client.listProjects();
    if (projects.length === 0) throw new ConfigError('You have no projects yet. Create one in the Unseen web app first.');
    projects.forEach((p, i) => ctx.out(`  ${i + 1}. ${p.name} (id ${p.id})`));
    const choice = Number(await ask('Which project is this repository?', '1'));
    const picked = projects[choice - 1];
    if (!picked) throw new UsageError('That is not one of the listed numbers.');
    projectId = picked.id;
  }

  const project = await client.project(projectId);
  const path = writeProjectConfig(ctx.cwd, { apiUrl, projectId: project.id });

  ctx.out(`Linked to "${project.name}" (id ${project.id}) as ${me.name}${source === 'env' ? ' using UNSEEN_TOKEN' : ''}.`);
  ctx.out(`Wrote ${path}. It holds no secrets, so commit it.`);
  ctx.out('Next: run "unseen status". See the CLI README for the CLAUDE.md snippet that tells Claude Code to use it.');
  return 0;
}
