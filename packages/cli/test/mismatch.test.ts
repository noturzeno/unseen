import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { writeProjectConfig } from '../src/config.ts';
import { main } from '../src/main.ts';
import { fakeFetch, makeContext } from './helpers.ts';

const mismatch = {
  id: 3, key: 'PRM-01-list', reference: 'PRD §4.11', title: 'Promo list vs text box', feature: { id: 1, name: 'Checkout' },
  prd_says: 'Show codes', design_says: 'Text box', built_to: 'design', built_note: null, impact: null, decide_by: 'client',
  status: 'decided', decision: 'follow_prd', decision_note: 'Client wants the list', next_action: 'Change the product to match the PRD.',
};

function linked() {
  const fetch = fakeFetch({
    'GET /api/v1/projects/5/features': () => ({ body: { data: [{ id: 1, name: 'Checkout', status: 'implemented', verification_status: 'not_checked', category: null, description: null }], meta: { last_page: 1 } } }),
    'POST /api/v1/projects/5/mismatches': (call) => ({ status: 201, body: { data: { ...mismatch, ...(call.body as object), status: 'open', decision: null } } }),
    'GET /api/v1/projects/5/mismatches': () => ({ body: { data: [mismatch] } }),
    'POST /api/v1/mismatches/3/applied': () => ({ body: { data: { ...mismatch, status: 'applied' } } }),
  });
  const made = makeContext({ fetch: fetch.impl });
  made.ctx.env = { ...made.ctx.env, UNSEEN_TOKEN: '7|' + 'T'.repeat(40) };
  writeProjectConfig(made.ctx.cwd, { apiUrl: 'http://127.0.0.1:8000', projectId: 5 });
  return { ...made, calls: fetch.calls };
}

test('mismatch add records a list, resolving feature names', async () => {
  const { ctx, out, err, calls } = linked();
  writeFileSync(join(ctx.cwd, 'm.json'), JSON.stringify([
    { key: 'PRM-01-list', title: 'Promo list vs text box', feature: 'checkout', prd_says: 'Show codes', design_says: 'Text box', built_to: 'design', decide_by: 'client' },
    { title: 'Plan order', prd_says: 'Plan first', design_says: 'Price first' },
  ]));
  assert.equal(await main(['mismatch', 'add', '--file', 'm.json'], ctx), 0, err.join('\n'));
  const posts = calls.filter((c) => c.method === 'POST');
  assert.equal(posts.length, 2);
  assert.equal((posts[0]!.body as any).feature_id, 1);
  assert.equal((posts[0]!.body as any).feature, undefined);
  assert.match(out.join('\n'), /Recorded 2 mismatches/);
});

test('mismatch add refuses decisions and bad values, sending nothing', async () => {
  for (const bad of [
    { title: 'x', prd_says: 'a', design_says: 'b', decision: 'follow_prd' },
    { title: 'x', prd_says: 'a' },
    { title: 'x', prd_says: 'a', design_says: 'b', built_to: 'figma' },
  ]) {
    const { ctx, err, calls } = linked();
    writeFileSync(join(ctx.cwd, 'm.json'), JSON.stringify(bad));
    assert.equal(await main(['mismatch', 'add', '--file', 'm.json'], ctx), 1);
    assert.equal(calls.filter((c) => c.method === 'POST').length, 0);
    if ('decision' in bad) assert.match(err.join('\n'), /Agents never decide mismatches/);
  }
});

test('mismatch list shows decisions to apply; applied marks one done', async () => {
  const { ctx, out, calls } = linked();
  assert.equal(await main(['mismatch', 'list', '--status', 'decided'], ctx), 0);
  assert.match(calls[0]!.url, /status=decided/);
  assert.match(out.join('\n'), /#3 Promo list vs text box \[decided: follow prd, to apply; built to design\]/);
  assert.match(out.join('\n'), /Next: Change the product to match the PRD\. Note: Client wants the list/);

  assert.equal(await main(['mismatch', 'applied', '3', '--note', 'Added the list'], ctx), 0);
  assert.deepEqual(calls.at(-1)!.body, { note: 'Added the list' });
  assert.equal(await main(['mismatch', 'applied', 'abc'], ctx), 2);
});
