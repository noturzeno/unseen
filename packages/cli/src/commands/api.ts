import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import { linkedProject, UsageError, type Context } from '../context.ts';
import { redactSecrets } from '../redact.ts';

export interface ApiOptions {
  method: string;
  path: string;
  data?: string;
  file?: string;
}

const METHODS = ['GET', 'POST', 'PUT', 'PATCH', 'DELETE'];

/**
 * Sends one request to the linked Unseen's /api/v1 with the stored token, so agents can record
 * features, statuses, criteria, checks and diagrams without ever handling the token themselves.
 * The path is always relative to /api/v1 on the linked server; likely secrets in the body are masked.
 */
export async function api(opts: ApiOptions, ctx: Context): Promise<number> {
  const method = opts.method.toUpperCase();
  if (!METHODS.includes(method)) throw new UsageError(`Method must be one of ${METHODS.join(', ')}.`);

  const path = opts.path.startsWith('/') ? opts.path : `/${opts.path}`;
  if (/^\/\/|:\/\/|(^|\/)\.\.(\/|$)|[\s#]/.test(path)) {
    throw new UsageError('Give a path relative to /api/v1, e.g. /projects/3/features.');
  }
  // Token management needs a person: agents must not list, create or revoke tokens.
  if (/^\/auth(\/|$)/.test(path)) throw new UsageError('Token management is not available to agents.');

  let body: unknown;
  const raw = opts.file === '-' ? await ctx.readStdin()
    : opts.file ? readFileSync(resolve(ctx.cwd, opts.file), 'utf8')
      : opts.data;
  if (raw !== undefined) {
    if (method === 'GET' || method === 'DELETE') throw new UsageError(`${method} requests take no body.`);
    try {
      body = JSON.parse(raw);
    } catch (error) {
      throw new UsageError(`The request body is not valid JSON: ${(error as Error).message}`);
    }
  }

  const { client, token } = linkedProject(ctx);
  if (body !== undefined) {
    const { value, redacted } = redactSecrets(body, [token]);
    if (redacted.length) ctx.err(`Masked likely secrets at: ${redacted.join(', ')}`);
    body = value;
  }

  const result = await client.request<unknown>(method, path, body);
  ctx.out(result === null ? '' : JSON.stringify(result, null, 2));
  return 0;
}
