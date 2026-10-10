import assert from 'node:assert/strict';
import { existsSync, mkdirSync, readFileSync, realpathSync } from 'node:fs';
import { homedir } from 'node:os';
import { join } from 'node:path';
import { test } from 'node:test';
import { writeProjectConfig } from '../src/config.ts';
import { markRunnerRunning, openLink, parseRunnerLink, runnerIsRunning, runnerPidPath } from '../src/link.ts';
import { makeContext, tempDir } from './helpers.ts';

test('only unseen://runner links with a full folder path are accepted', () => {
  assert.deepEqual(parseRunnerLink('unseen://runner'), { folder: null });
  assert.deepEqual(parseRunnerLink('unseen://runner?folder=%2FUsers%2Fdev%2Fapp'), { folder: '/Users/dev/app' });
  for (const bad of ['https://runner', 'unseen://report', 'unseen://runner?folder=relative', 'unseen://runner?folder=%2Fx%0Arm', 'nonsense']) {
    assert.throws(() => parseRunnerLink(bad), undefined, bad);
  }
});

test('open-link starts the runner in a linked folder, or the home folder for anything else', async () => {
  const repo = tempDir();
  writeProjectConfig(repo, { apiUrl: 'http://127.0.0.1:8000', projectId: 7 });
  const unlinked = tempDir();

  for (const [folder, expected] of [[repo, realpathSync(repo)], [unlinked, homedir()], ['/nope/missing', homedir()]] as const) {
    const { ctx, out } = makeContext();
    const scripts: string[] = [];
    const code = await openLink(`unseen://runner?folder=${encodeURIComponent(folder)}`, ctx, {
      terminal: async (script) => { scripts.push(readFileSync(script, 'utf8')); return { ok: true, how: 'a test window' }; },
    });
    assert.equal(code, 0);
    assert.match(scripts[0]!, new RegExp(`cd -- '${expected.replace(/[/.]/g, '\\$&')}'`));
    assert.match(scripts[0]!, / runner\n/);
    assert.match(out.join('\n'), /Started the Unseen runner/);
  }
});

test('open-link does not start a second runner', async () => {
  const { ctx, out } = makeContext();
  mkdirSync(join(ctx.env.UNSEEN_CONFIG_DIR!), { recursive: true });
  const cleanup = markRunnerRunning(ctx.env);
  assert.ok(runnerIsRunning(ctx.env));

  let opened = false;
  await openLink('unseen://runner', ctx, { terminal: async () => { opened = true; return { ok: true, how: 'x' }; } });
  assert.equal(opened, false);
  assert.match(out.join('\n'), /already running/);

  cleanup();
  assert.equal(existsSync(runnerPidPath(ctx.env)), false);
  assert.equal(runnerIsRunning(ctx.env), false);
});
