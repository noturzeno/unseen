import assert from 'node:assert/strict';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { writeProjectConfig } from '../src/config.ts';
import { main } from '../src/main.ts';
import { redactPersonalData } from '../src/redact.ts';
import { fakeFetch, makeContext } from './helpers.ts';

const API = 'http://127.0.0.1:8000';

const ticket = {
  id: 7, reference: 'WEB-142', title: 'Pay button dead on Safari', description: null, type: 'bug', status: 'ready_for_qa',
  priority: 'high', raised_by: 'client', feature: { id: 1, name: 'Checkout' }, comments_count: 2, latest_feedback: null,
  updated_at: '2026-10-09T08:00:00Z',
  comments: [
    { id: 1, ticket_id: 7, author_role: 'client', author_name: null, body: 'Nothing happens', from_status: null, to_status: 'open', report_id: null, created_at: '2026-10-08T08:00:00Z' },
    { id: 2, ticket_id: 7, author_role: 'agent', author_name: null, body: null, from_status: 'open', to_status: 'ready_for_qa', report_id: 42, created_at: '2026-10-09T08:00:00Z' },
  ],
};

function server() {
  return fakeFetch({
    'GET /api/v1/projects/5/features': () => ({ body: { data: [{ id: 1, name: 'Checkout', status: 'implemented', verification_status: 'not_checked', category: null, description: null }], meta: { last_page: 1 } } }),
    'GET /api/v1/projects/5/tickets': (call) => ({
      body: { data: /reference=nope/.test(call.url) ? [] : [ticket], meta: { last_page: 1 } },
    }),
    'GET /api/v1/tickets/7': () => ({ body: { data: ticket } }),
    'POST /api/v1/projects/5/tickets': (call) => ({ status: 201, body: { data: { ...ticket, ...(call.body as object), status: 'open' } } }),
    'PATCH /api/v1/tickets/7': (call) => ({ body: { data: { ...ticket, status: (call.body as any).status ?? ticket.status } } }),
    'POST /api/v1/tickets/7/comments': (call) => ({
      status: 201,
      body: { data: { comment: { ...ticket.comments[0], ...(call.body as object), to_status: (call.body as any).status ?? null }, ticket } },
    }),
    'POST /api/v1/projects/5/reports': (call) => ({ status: 201, body: { data: { id: 43, features: [], tickets: [{ id: 7, label: 'WEB-142', title: ticket.title, to_status: (call.body as any).tickets?.[0]?.status ?? null }] } } }),
  });
}

function linked() {
  const fetch = server();
  const made = makeContext({ fetch: fetch.impl });
  made.ctx.env = { ...made.ctx.env, UNSEEN_TOKEN: '7|' + 'T'.repeat(40) };
  writeProjectConfig(made.ctx.cwd, { apiUrl: API, projectId: 5 });
  return { ...made, calls: fetch.calls };
}

test('ticket add records the ticket with its feature resolved by name, masking personal data', async () => {
  const { ctx, out, err, calls } = linked();
  const code = await main(['ticket', 'add', '--title', 'Pay button dead on Safari', '--ref', 'WEB-142', '--type', 'bug',
    '--feature', 'checkout', '--raised-by', 'client', '--comment', 'Call me on +852 9123 4567 or mail amy@client.example'], ctx);
  assert.equal(code, 0, err.join('\n'));

  const sent = calls.find((c) => c.method === 'POST')!.body as any;
  assert.equal(sent.feature_id, 1);
  assert.equal(sent.raised_by, 'client');
  assert.equal(sent.comment, 'Call me on [PHONE] or mail [EMAIL]');
  assert.match(err.join('\n'), /Masked emails, phone or ID numbers in: \$\.comment/);
  assert.match(out.join('\n'), /Ticket recorded\.\nWEB-142 Pay button dead on Safari/);
});

test('ticket comment records QA feedback by reference and can move the ticket', async () => {
  const { ctx, out, err, calls } = linked();
  assert.equal(await main(['ticket', 'comment', 'web-142', '--role', 'qa', '--body', 'Still fails on iOS 17', '--status', 'qa_failed'], ctx), 0, err.join('\n'));

  assert.match(calls[0]!.url, /reference=web-142/);
  assert.deepEqual(calls[1]!.body, { author_role: 'qa', body: 'Still fails on iOS 17', status: 'qa_failed' });
  assert.match(out.join('\n'), /QA comment recorded on WEB-142, now qa failed/);
});

test('ticket comment reads the body from a file and requires a role', async () => {
  const { ctx, err, calls } = linked();
  writeFileSync(join(ctx.cwd, 'feedback.txt'), 'Client: love it, ship it\n');
  assert.equal(await main(['ticket', 'comment', '#7', '--role', 'client', '--file', 'feedback.txt', '--status', 'done'], ctx), 0, err.join('\n'));
  assert.equal(calls[0]!.url, '/api/v1/tickets/7'); // #id skips the lookup by reference
  assert.equal((calls[1]!.body as any).body, 'Client: love it, ship it');

  const bad = linked();
  assert.equal(await main(['ticket', 'comment', 'WEB-142', '--body', 'x'], bad.ctx), 2);
  assert.match(bad.err.join('\n'), /--role/);
  assert.equal(await main(['ticket', 'comment', 'WEB-142', '--role', 'boss', '--body', 'x'], bad.ctx), 2);
  assert.equal(bad.calls.length, 0);
});

test('ticket move, show and list', async () => {
  const { ctx, out, err, calls } = linked();
  assert.equal(await main(['ticket', 'move', 'WEB-142', 'client_review', '--note', 'QA passed'], ctx), 0, err.join('\n'));
  assert.deepEqual(calls.at(-1)!.body, { status: 'client_review', note: 'QA passed' });
  assert.equal(await main(['ticket', 'move', 'WEB-142', 'shipped'], ctx), 2);

  assert.equal(await main(['ticket', 'show', 'WEB-142'], ctx), 0);
  const shown = out.join('\n');
  assert.match(shown, /Thread:/);
  assert.match(shown, /Client opened as open\n {6}Nothing happens/);
  assert.match(shown, /Agent moved open → ready for qa \(report #42\)/);

  assert.equal(await main(['ticket', 'list'], ctx), 0);
  assert.match(calls.at(-1)!.url, /state=open/);
  assert.equal(await main(['ticket', 'list', '--status', 'qa_failed', '--json'], ctx), 0);
  assert.match(calls.at(-1)!.url, /status=qa_failed/);

  const missing = linked();
  assert.equal(await main(['ticket', 'show', 'nope'], missing.ctx), 1);
  assert.match(missing.err.join('\n'), /No ticket "nope"/);
});

test('reports link tickets by reference with the new status', async () => {
  const { ctx, out, err, calls } = linked();
  writeFileSync(join(ctx.cwd, 'r.json'), JSON.stringify({ summary: 'Fixed Safari tap', tickets: [{ ref: 'WEB-142', status: 'ready_for_qa', note: 'touchend bound' }] }));
  assert.equal(await main(['report', '--file', 'r.json', '--no-git'], ctx), 0, err.join('\n'));
  const sent = calls.find((c) => c.method === 'POST')!.body as any;
  assert.deepEqual(sent.tickets, [{ id: 7, status: 'ready_for_qa', note: 'touchend bound' }]);
  assert.match(out.join('\n'), /WEB-142 Pay button dead on Safari: now ready for qa/);

  const bad = linked();
  writeFileSync(join(bad.ctx.cwd, 'r.json'), JSON.stringify({ summary: 'x', tickets: ['WEB-999'] }));
  assert.equal(await main(['report', '--file', 'r.json', '--no-git'], bad.ctx), 1);
  assert.match(bad.err.join('\n'), /no ticket "WEB-999"/);
});

test('personal data masking keeps ordinary numbers and ticket text', () => {
  const { value } = redactPersonalData({
    a: 'Version 2.10.3 fails on iOS 17.4, see WEB-142 and order 12345',
    b: 'HKID A123456(7), phone 2345-6789, email qa.lead@agency.example',
  });
  assert.equal(value.a, 'Version 2.10.3 fails on iOS 17.4, see WEB-142 and order 12345');
  assert.equal(value.b, 'HKID [ID NUMBER], phone [PHONE], email [EMAIL]');
});
