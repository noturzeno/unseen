import { mkdtempSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import type { Context } from '../src/context.ts';

export interface Call {
  method: string;
  url: string;
  headers: Record<string, string>;
  body: unknown;
}

type Route = (call: Call) => { status?: number; body: unknown };

/** Fake fetch: routes are matched on "METHOD /path" (path without query string). */
export function fakeFetch(routes: Record<string, Route>) {
  const calls: Call[] = [];
  const impl = (async (input: string | URL, init: RequestInit = {}) => {
    const url = new URL(String(input));
    const call: Call = {
      method: init.method ?? 'GET',
      url: url.pathname + url.search,
      headers: init.headers as Record<string, string>,
      body: init.body ? JSON.parse(String(init.body)) : undefined,
    };
    calls.push(call);
    const route = routes[`${call.method} ${url.pathname}`];
    const { status = 200, body } = route ? route(call) : { status: 404, body: { message: 'Not found' } };
    return new Response(JSON.stringify(body), { status, headers: { 'Content-Type': 'application/json' } });
  }) as typeof fetch;
  return { impl, calls };
}

export function tempDir(): string {
  return mkdtempSync(join(tmpdir(), 'unseen-cli-test-'));
}

export function makeContext(overrides: Partial<Context> = {}) {
  const out: string[] = [];
  const err: string[] = [];
  const ctx: Context = {
    cwd: tempDir(),
    env: { UNSEEN_CONFIG_DIR: tempDir() },
    out: (t) => out.push(t),
    err: (t) => err.push(t),
    fetch: fakeFetch({}).impl,
    interactive: false,
    readStdin: async () => '',
    ...overrides,
  };
  return { ctx, out, err };
}
