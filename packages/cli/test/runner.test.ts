import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { existsSync, mkdirSync, realpathSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { runner } from '../src/commands/runner.ts';
import { writeProjectConfig } from '../src/config.ts';
import { shellQuote, writeLaunchScript, type LaunchRequest } from '../src/launch.ts';
import { fakeFetch, makeContext, tempDir } from './helpers.ts';

const API = 'http://127.0.0.1:8000';
const TOKEN = '41|' + 'R'.repeat(40);

function setup(localPath: (repo: string) => string | null, linkedTo = { apiUrl: API, projectId: 7 }) {
  const repo = tempDir();
  writeProjectConfig(repo, linkedTo);
  const { ctx, out, err } = makeContext();
  writeFileSync(join(ctx.env.UNSEEN_CONFIG_DIR!, 'credentials.json'), JSON.stringify({ tokens: { [`${API}#project=7`]: TOKEN } }));
  const runs = [{ id: 3, project_id: 7, title: 'Continue building it: Wallets', prompt: "Build \"Wallets\" — it's #12", local_path: localPath(repo), requested_by: 'Zeno' }];
  const server = fakeFetch({
    'POST /api/v1/projects/7/runs/claim': () => (runs.length ? { body: { data: runs.shift() } } : { status: 204, body: null }),
    'PATCH /api/v1/runs/3': (call) => ({ body: { data: { id: 3, status: (call.body as any).status } } }),
  });
  ctx.fetch = server.impl;
  const launched: LaunchRequest[] = [];
  const launch = async (r: LaunchRequest) => { launched.push(r); return { ok: true as const, how: 'a test window' }; };
  return { repo, ctx, out, err, server, launched, launch };
}

test('runner claims a Proceed request and opens Claude Code in the linked project folder', async () => {
  const s = setup((repo) => repo);

  assert.equal(await runner({ once: true }, s.ctx, { launch: s.launch }), 0);

  assert.equal(s.launched.length, 1);
  assert.equal(s.launched[0]!.prompt, "Build \"Wallets\" — it's #12");
  assert.equal(s.launched[0]!.claude, 'claude');
  const claim = s.server.calls[0]!;
  assert.equal(claim.headers.Authorization, `Bearer ${TOKEN}`);
  assert.ok((claim.body as any).runner, 'sends the runner name');
  assert.deepEqual(s.server.calls.find((c) => c.method === 'PATCH')!.body, { status: 'launched' });
  assert.match(s.out.join('\n'), /→ Continue building it: Wallets \(from Zeno\)/);
});

test('runner started in the linked repo sends its folder and opens there when Unseen has none set', async () => {
  const s = setup(() => null);
  s.ctx.cwd = s.repo;

  await runner({ once: true }, s.ctx, { launch: s.launch });

  assert.equal((s.server.calls[0]!.body as any).folder, realpathSync(s.repo));
  assert.equal(s.launched.length, 1);
  assert.equal(s.launched[0]!.folder, realpathSync(s.repo));
});

test('runner refuses folders that are missing, unlinked, or linked to another project', async () => {
  for (const [localPath, linkedTo, message] of [
    [() => null, undefined, /No project folder is set/],
    [() => '/nope/not/here', undefined, /Folder not found on this computer/],
    [(repo: string) => { const d = join(repo, 'other'); mkdirSync(d); return d; }, undefined, /isn't linked to Unseen/],
    [(repo: string) => repo, { apiUrl: API, projectId: 99 }, /linked to project 99/],
    [(repo: string) => repo, { apiUrl: 'https://other.example.com', projectId: 7 }, /linked to project 7 on https:\/\/other.example.com/],
  ] as const) {
    const s = setup(localPath as any, linkedTo as any);
    await runner({ once: true }, s.ctx, { launch: s.launch });
    assert.equal(s.launched.length, 0, String(message));
    const patch = s.server.calls.find((c) => c.method === 'PATCH')!;
    assert.equal((patch.body as any).status, 'failed');
    assert.match((patch.body as any).error, message);
  }
});

test('runner suggests the linked subfolder when the project folder is its parent, but does not open it', async () => {
  const parent = tempDir();
  const nested = join(parent, 'app-repo');
  mkdirSync(nested);
  writeProjectConfig(nested, { apiUrl: API, projectId: 7 });
  const s = setup(() => parent);

  await runner({ once: true }, s.ctx, { launch: s.launch });

  assert.equal(s.launched.length, 0);
  const patch = s.server.calls.find((c) => c.method === 'PATCH')!;
  assert.match((patch.body as any).error, /isn't linked to Unseen, but .*app-repo is\. Set that as the project folder/);
});

test('runner stops watching a project whose token is refused, and needs at least one linked project', async () => {
  const s = setup((repo) => repo);
  s.ctx.fetch = fakeFetch({ 'POST /api/v1/projects/7/runs/claim': () => ({ status: 403, body: { message: 'Forbidden' } }) }).impl;
  assert.equal(await runner({ once: true }, s.ctx, { launch: s.launch }), 1);
  assert.match(s.err.join('\n'), /Stopped watching project 7/);

  const empty = makeContext();
  await assert.rejects(runner({ once: true }, empty.ctx), /No linked projects on this computer/);
});

test('launch script cds into the folder, reads the prompt from a private file, deletes it, and starts claude', () => {
  const folder = tempDir();
  const script = writeLaunchScript({ folder, prompt: "it's a \"test\" $(rm -rf /) `x`", title: 'Fix it', claude: 'echo' });
  const promptFile = join(script, '..', 'prompt.txt');
  assert.ok(existsSync(promptFile));

  const output = execFileSync('bash', [script], { encoding: 'utf8' });

  assert.match(output, /Unseen: Fix it/);
  assert.match(output, /it's a "test" \$\(rm -rf \/\) `x`/); // passed as data, never run
  assert.ok(!existsSync(promptFile), 'prompt file deleted');
  assert.equal(shellQuote("a'b"), `'a'\\''b'`);
});

test('launch script loads the folder\'s direnv environment, so the folder\'s Claude account is used', () => {
  const folder = tempDir();
  const bin = tempDir();
  // Stand-ins: direnv that sets CLAUDE_CONFIG_DIR for this folder, and a "claude" that prints what it got.
  writeFileSync(join(bin, 'direnv'), `#!/bin/sh\n[ "$1" = export ] && echo "export CLAUDE_CONFIG_DIR='/Users/x/.claude-visibleone';"\n`, { mode: 0o755 });
  writeFileSync(join(bin, 'fake-claude'), '#!/bin/sh\necho "config=$CLAUDE_CONFIG_DIR prompt=$1"\n', { mode: 0o755 });

  const script = writeLaunchScript({ folder, prompt: 'Do it', title: 'T', claude: join(bin, 'fake-claude') });
  const output = execFileSync('bash', [script], { encoding: 'utf8', env: { ...process.env, PATH: `${bin}:${process.env.PATH}`, CLAUDE_CONFIG_DIR: '' } });

  assert.match(output, /Claude account: \/Users\/x\/.claude-visibleone/);
  assert.match(output, /config=\/Users\/x\/.claude-visibleone prompt=Do it/);
});
