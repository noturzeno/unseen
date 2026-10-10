import assert from 'node:assert/strict';
import { existsSync, readFileSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { writeProjectConfig } from '../src/config.ts';
import { VERSION } from '../src/api.ts';
import { main } from '../src/main.ts';
import { fakeFetch, makeContext } from './helpers.ts';

const API = 'http://127.0.0.1:8000';

const dashboard = {
  project: { id: 5, name: 'Portal', description: 'Client portal', repository_url: null, archived: false },
  summary: {
    total: 2,
    by_status: { planned: 1, in_progress: 0, implemented: 1, blocked: 0, not_implemented: 0 },
    by_verification_status: { not_checked: 1, needs_verification: 1, verified: 0, failed: 0 },
    implemented_and_verified: 0,
    needs_attention: 1,
    progress: { implemented_percent: 50, verified_percent: 0 },
    reports: 1,
    open_unresolved: 1,
    last_report_at: null,
  },
  needs_attention: [{ id: 2, name: 'Login', status: 'implemented', verification_status: 'needs_verification', category: null, description: null }],
  unresolved: [{ id: 31, text: 'Rate limiting', report_id: 9, reported_at: '2026-10-01T00:00:00Z' }],
  recent_reports: [],
};

const features = [
  { id: 1, name: 'Password reset', description: 'Reset by email', status: 'planned', verification_status: 'not_checked', category: { id: 1, name: 'Auth' }, acceptance_criteria: [{ description: 'Link expires' }] },
  { id: 2, name: 'Login', description: null, status: 'implemented', verification_status: 'needs_verification', category: null, acceptance_criteria: [] },
];

const roadmapData = {
  synced_at: '2026-10-09T06:00:00Z',
  phases: [
    { key: 'M1', name: 'Foundation', description: null, status: 'done', target_date: null,
      progress: { items: 2, done: 2, percent: 100, features: 1, implemented: 1, verified: 1 },
      items: [{ text: 'Sign in', done: true, feature: null }, { text: 'Projects', done: true, feature: null }] },
    { key: 'M2', name: 'Reports', description: null, status: 'in_progress', target_date: '2026-11-01',
      progress: { items: 2, done: 1, percent: 50, features: 0, implemented: 0, verified: 0 },
      items: [{ text: 'Report API', done: true, feature: null }, { text: 'Report UI', done: false, feature: null }] },
  ],
};

function serverRoutes() {
  return fakeFetch({
    'GET /api/v1/me': () => ({ body: { data: { id: 1, name: 'Dev', email: 'dev@example.com' } } }),
    'GET /api/v1/projects/5': () => ({ body: { data: dashboard.project } }),
    'GET /api/v1/projects/5/dashboard': () => ({ body: { data: dashboard } }),
    'GET /api/v1/projects/5/features': () => ({ body: { data: features, meta: { last_page: 1 } } }),
    'GET /api/v1/projects/5/roadmap': () => ({ body: { data: roadmapData } }),
    'POST /api/v1/projects/5/reports': (call) => ({
      status: 201,
      body: { data: { id: 42, features: ((call.body as any).features ?? []).map((f: any) => ({ id: f.id, name: 'Password reset', claimed_status: f.status ?? null })) } },
    }),
  });
}

function linked(fetchImpl: typeof fetch, env: Record<string, string> = {}) {
  const made = makeContext({ fetch: fetchImpl });
  made.ctx.env = { ...made.ctx.env, UNSEEN_TOKEN: '7|' + 'T'.repeat(40), ...env };
  writeProjectConfig(made.ctx.cwd, { apiUrl: API, projectId: 5 });
  return made;
}

test('commands explain how to link when the repo is not linked', async () => {
  const { ctx, err } = makeContext();
  assert.equal(await main(['status'], ctx), 1);
  assert.match(err.join('\n'), /unseen init/);
});

test('init (non-interactive) verifies access and writes .unseen.json without secrets', async () => {
  const server = serverRoutes();
  const { ctx, out } = makeContext({ fetch: server.impl });
  ctx.env.UNSEEN_TOKEN = 'secret-token-value';

  assert.equal(await main(['init', '--api-url', `${API}/`, '--project', '5'], ctx), 0);

  const file = readFileSync(join(ctx.cwd, '.unseen.json'), 'utf8');
  assert.deepEqual(JSON.parse(file), { apiUrl: API, projectId: 5 });
  assert.ok(!file.includes('secret-token-value'));
  assert.match(out.join('\n'), /Linked to "Portal"/);
  assert.equal(server.calls[0]!.headers.Authorization, 'Bearer secret-token-value');
});

test('init refuses to overwrite an existing link without --force, and needs flags when non-interactive', async () => {
  const { ctx, err } = linked(serverRoutes().impl);
  assert.equal(await main(['init', '--api-url', API, '--project', '5'], ctx), 2);
  assert.match(err.join('\n'), /--force/);

  const fresh = makeContext();
  fresh.ctx.env.UNSEEN_TOKEN = 't';
  assert.equal(await main(['init'], fresh.ctx), 2);
  assert.ok(!existsSync(join(fresh.ctx.cwd, '.unseen.json')));
});

test('init without any token fails clearly when not interactive', async () => {
  const { ctx, err } = makeContext();
  assert.equal(await main(['init', '--api-url', API, '--project', '5'], ctx), 1);
  assert.match(err.join('\n'), /UNSEEN_TOKEN/);
});

test('status prints a readable summary with feature ids', async () => {
  const { ctx, out } = linked(serverRoutes().impl);
  assert.equal(await main(['status'], ctx), 0);

  const text = out.join('\n');
  assert.match(text, /Progress: 50% implemented, 0% implemented and verified \(2 features\)/);
  assert.match(text, /#2 Login: implemented, needs verification/);
  assert.match(text, /#31 Rate limiting \(from report #9\)/);
  assert.match(text, /Auth\n {4}#1 Password reset: planned \/ not checked/);
});

test('status --json gives machine-readable context', async () => {
  const { ctx, out } = linked(serverRoutes().impl);
  assert.equal(await main(['status', '--json'], ctx), 0);

  const data = JSON.parse(out.join('\n'));
  assert.equal(data.project.url, `${API}/projects/5`);
  assert.deepEqual(data.features[0].acceptance_criteria, ['Link expires']);
  assert.equal(data.summary.progress.implemented_percent, 50);
});

test('report submits a normalised, redacted payload', async () => {
  const server = serverRoutes();
  const { ctx, out, err } = linked(server.impl);
  writeFileSync(join(ctx.cwd, 'report.json'), JSON.stringify({
    summary: 'Added reset flow',
    features: [{ name: 'password reset', status: 'implemented' }],
    changed_files: ['app/Reset.php'],
    tests: [{ command: 'DB_PASSWORD=hunter2 php artisan test', outcome: 'passed' }],
  }));

  assert.equal(await main(['report', '--file', 'report.json', '--agent', 'claude-code', '--no-git'], ctx), 0);

  const post = server.calls.find((c) => c.method === 'POST')!;
  assert.deepEqual(post.body, {
    summary: 'Added reset flow',
    features: [{ id: 1, status: 'implemented' }],
    changed_files: [{ path: 'app/Reset.php' }],
    tests: [{ command: 'DB_PASSWORD=[REDACTED] php artisan test', outcome: 'passed' }],
    agent: 'claude-code',
  });
  assert.match(err.join('\n'), /Masked .*\$\.tests\[0\]\.command/);
  assert.match(out.join('\n'), /Report #42 saved: http:\/\/127\.0\.0\.1:8000\/reports\/42/);
  assert.match(out.join('\n'), /do not verify/);
});

test('report reads JSON from stdin and supports --summary alone', async () => {
  const server = serverRoutes();
  const { ctx } = linked(server.impl);
  ctx.readStdin = async () => JSON.stringify({ summary: 'From stdin', unresolved: ['x'] });

  assert.equal(await main(['report', '--no-git'], ctx), 0);
  assert.deepEqual(server.calls.at(-1)!.body, { summary: 'From stdin', unresolved: ['x'] });

  assert.equal(await main(['report', '--summary', 'Quick note', '--no-git'], ctx), 0);
  assert.deepEqual(server.calls.at(-1)!.body, { summary: 'Quick note' });
});

test('report --dry-run sends nothing', async () => {
  const server = serverRoutes();
  const { ctx, out } = linked(server.impl);

  assert.equal(await main(['report', '--summary', 'Try', '--dry-run', '--no-git'], ctx), 0);
  assert.equal(server.calls.filter((c) => c.method === 'POST').length, 0);
  assert.deepEqual(JSON.parse(out.join('\n')), { summary: 'Try' });
});

test('report never leaks the CLI token even if the agent pastes it', async () => {
  const server = serverRoutes();
  const { ctx } = linked(server.impl);
  const token = ctx.env.UNSEEN_TOKEN!;

  assert.equal(await main(['report', '--summary', `used ${token} to test`, '--no-git'], ctx), 0);
  assert.ok(!JSON.stringify(server.calls.at(-1)!.body).includes(token));
});

test('API validation errors are shown per field', async () => {
  const server = fakeFetch({
    'POST /api/v1/projects/5/reports': () => ({ status: 422, body: { message: 'Invalid.', errors: { 'tests.0.outcome': ['The selected outcome is invalid.'] } } }),
  });
  const { ctx, err } = linked(server.impl);

  assert.equal(await main(['report', '--summary', 'x', '--no-git'], ctx), 1);
  assert.match(err.join('\n'), /tests\.0\.outcome: The selected outcome is invalid\./);
});

test('401 and 403 responses give actionable messages', async () => {
  const unauth = linked(fakeFetch({ 'GET /api/v1/projects/5/dashboard': () => ({ status: 401, body: {} }) }).impl);
  assert.equal(await main(['status'], unauth.ctx), 1);
  assert.match(unauth.err.join('\n'), /unseen init/);

  const forbidden = linked(fakeFetch({ 'GET /api/v1/projects/5/dashboard': () => ({ status: 403, body: {} }) }).impl);
  assert.equal(await main(['status'], forbidden.ctx), 1);
  assert.match(forbidden.err.join('\n'), /Not allowed/);
});

test('scan sends candidates and links to the review page', async () => {
  const server = fakeFetch({
    'POST /api/v1/projects/5/scans': (call) => ({
      status: 201,
      body: { data: { id: 3, candidates: (call.body as any).candidates.length, new: 1, updated: 0, pending_review: 1 } },
    }),
  });
  const { ctx, out } = linked(server.impl);
  const { mkdirSync } = await import('node:fs');
  mkdirSync(join(ctx.cwd, 'app/Models'), { recursive: true });
  writeFileSync(join(ctx.cwd, 'app/Models/Invoice.php'), '<?php // API_KEY=abc123');

  assert.equal(await main(['scan', '--no-git'], ctx), 0);

  const body = server.calls[0]!.body as any;
  assert.equal(body.scanner, `unseen-cli ${VERSION}`);
  assert.deepEqual(body.candidates.map((c: any) => c.key), ['invoice']);
  assert.ok(!JSON.stringify(body).includes('abc123'));
  assert.match(out.join('\n'), /1 waiting for review: http:\/\/127\.0\.0\.1:8000\/projects\/5\/suggestions/);
});

test('scan --dry-run lists candidates and sends nothing', async () => {
  const server = fakeFetch({});
  const { ctx, out } = linked(server.impl);
  const { mkdirSync } = await import('node:fs');
  mkdirSync(join(ctx.cwd, 'routes'), { recursive: true });
  writeFileSync(join(ctx.cwd, 'routes/web.php'), "<?php Route::get('/invoices', X::class);");

  assert.equal(await main(['scan', '--dry-run', '--no-git'], ctx), 0);
  assert.equal(server.calls.length, 0);
  assert.match(out.join('\n'), /Invoice \(1: route\)/);
});

const JUNIT = `<testsuites><testsuite>
  <testcase classname="Tests.Feature.InvoiceTest" name="test_list"/>
  <testcase classname="Tests.Feature.InvoiceTest" name="test_total"><failure message="DB_PASSWORD=hunter2 leaked"/></testcase>
</testsuite></testsuites>`;

test('verify sends parsed, redacted results and summarises the effect', async () => {
  const server = fakeFetch({
    'POST /api/v1/projects/5/test-runs': () => ({
      status: 201,
      body: { data: { id: 8, totals: { total: 2, passed: 1, failed: 1, skipped: 0 }, features: { failed: 1, not_covered: 2 }, feature_outcomes: [] } },
    }),
  });
  const { ctx, out } = linked(server.impl);
  writeFileSync(join(ctx.cwd, 'junit.xml'), JUNIT);

  assert.equal(await main(['verify', '--junit', 'junit.xml', '--no-git'], ctx), 0);

  const body = server.calls[0]!.body as any;
  assert.equal(body.git_dirty, false);
  assert.deepEqual(body.cases.map((c: any) => [c.id, c.outcome]), [
    ['Tests.Feature.InvoiceTest::test_list', 'passed'],
    ['Tests.Feature.InvoiceTest::test_total', 'failed'],
  ]);
  assert.equal(body.cases[1].message, 'DB_PASSWORD=[REDACTED] leaked');
  const text = out.join('\n');
  assert.match(text, /Test run #8: 1 passed, 1 failed, 0 skipped/);
  assert.match(text, /1 marked failed, 2 not covered by this run/);
});

test('verify --run runs the command and returns its exit code', async () => {
  const server = fakeFetch({
    'POST /api/v1/projects/5/test-runs': () => ({ status: 201, body: { data: { id: 9, totals: { total: 2, passed: 1, failed: 1, skipped: 0 }, features: {}, feature_outcomes: [] } } }),
  });
  const { ctx } = linked(server.impl);
  const report = join(ctx.cwd, 'out.xml');
  const script = join(ctx.cwd, 'fake-tests.mjs');
  writeFileSync(script, `import { writeFileSync } from 'node:fs'; writeFileSync(${JSON.stringify(report)}, ${JSON.stringify(JUNIT)}); process.exit(3);`);

  assert.equal(await main(['verify', '--run', `node ${script}`, '--junit', 'out.xml', '--no-git'], ctx), 3);
  assert.equal(server.calls.length, 1);
});

test('verify explains when the token lacks the verify ability', async () => {
  const server = fakeFetch({ 'POST /api/v1/projects/5/test-runs': () => ({ status: 403, body: { message: 'Invalid ability provided.' } }) });
  const { ctx, err } = linked(server.impl);
  writeFileSync(join(ctx.cwd, 'junit.xml'), JUNIT);

  assert.equal(await main(['verify', '--junit', 'junit.xml', '--no-git'], ctx), 1);
  assert.match(err.join('\n'), /"verify" ability/);
});

test('verify validates its inputs', async () => {
  const { ctx, err } = linked(fakeFetch({}).impl);
  assert.equal(await main(['verify'], ctx), 2);
  assert.equal(await main(['verify', '--junit', 'missing.xml', '--no-git'], ctx), 2);
  writeFileSync(join(ctx.cwd, 'bad.xml'), '<html></html>');
  assert.equal(await main(['verify', '--junit', 'bad.xml', '--no-git'], ctx), 1);
  assert.match(err.join('\n'), /JUnit/);
});

test('usage errors exit with code 2', async () => {
  const { ctx, err } = makeContext();
  assert.equal(await main(['report', '--bogus'], ctx), 2);
  assert.equal(await main(['nope'], ctx), 2);
  assert.match(err.join('\n'), /--help/);
});

test('api sends one authenticated request and prints the JSON response', async () => {
  const server = fakeFetch({
    'POST /api/v1/projects/5/features': (call) => ({ status: 201, body: { data: { id: 9, ...(call.body as object) } } }),
  });
  const { ctx, out } = linked(server.impl);

  const code = await main(['api', 'post', 'projects/5/features', '--data', '{"name":"Invoices","status":"in_progress"}'], ctx);

  assert.equal(code, 0);
  assert.equal(server.calls[0].method, 'POST');
  assert.equal(server.calls[0].url, '/api/v1/projects/5/features');
  assert.match(server.calls[0].headers.Authorization, /^Bearer 7\|T+$/);
  assert.deepEqual(server.calls[0].body, { name: 'Invoices', status: 'in_progress' });
  assert.equal(JSON.parse(out.join('\n')).data.id, 9);
  assert.ok(!out.join('\n').includes('T'.repeat(40)), 'token is never printed');
});

test('api masks secrets in the body and reads it from a file', async () => {
  const server = fakeFetch({ 'PATCH /api/v1/features/9': (call) => ({ body: { data: call.body } }) });
  const { ctx, err } = linked(server.impl);
  writeFileSync(join(ctx.cwd, 'feature.json'), JSON.stringify({ description: 'Uses api_key=abc123secret to call Stripe' }));

  assert.equal(await main(['api', 'PATCH', '/features/9', '--file', 'feature.json'], ctx), 0);
  assert.equal((server.calls[0].body as any).description, 'Uses api_key=[REDACTED] to call Stripe');
  assert.match(err.join('\n'), /Masked likely secrets/);
});

test('api refuses token management, escaping paths, bad JSON and unknown methods', async () => {
  const server = fakeFetch({});
  const { ctx, err } = linked(server.impl);

  for (const args of [
    ['api', 'GET', '/auth/tokens'],
    ['api', 'DELETE', '/auth/tokens/3'],
    ['api', 'GET', '//evil.example.com/x'],
    ['api', 'GET', 'https://evil.example.com/x'],
    ['api', 'GET', '/projects/../../admin'],
    ['api', 'POST', '/projects/5/features', '--data', '{not json'],
    ['api', 'GET', '/projects/5', '--data', '{}'],
    ['api', 'TRACE', '/projects/5'],
    ['api', 'GET'],
  ]) {
    assert.equal(await main(args, ctx), 2, args.join(' '));
  }
  assert.equal(server.calls.length, 0, 'nothing was sent');
  assert.match(err.join('\n'), /Token management is not available to agents/);
});

test('api shows validation errors from the server', async () => {
  const server = fakeFetch({
    'PATCH /api/v1/features/9': () => ({ status: 422, body: {
      message: 'Only trusted test runs can mark a feature verified or failed.',
      errors: { verification_status: ['Only trusted test runs can mark a feature verified or failed.'] },
    } }),
  });
  const { ctx, err } = linked(server.impl);

  assert.equal(await main(['api', 'PATCH', '/features/9', '-d', '{"verification_status":"verified"}'], ctx), 1);
  assert.match(err.join('\n'), /Only trusted test runs/);
});

test('init --token-stdin checks and saves a pasted token, outside the repository', async () => {
  const token = '12|' + 'a'.repeat(40) + 'deadbeef';
  const server = serverRoutes();
  const { ctx, out } = makeContext({ fetch: server.impl, readStdin: async () => `${token}\n` });

  assert.equal(await main(['init', '--api-url', API, '--project', '5', '--token-stdin'], ctx), 0);

  const saved = readFileSync(join(ctx.env.UNSEEN_CONFIG_DIR!, 'credentials.json'), 'utf8');
  assert.ok(saved.includes(token), 'token saved to the credentials file');
  assert.ok(!readFileSync(join(ctx.cwd, '.unseen.json'), 'utf8').includes(token), 'not in the repo');
  assert.ok(!out.join('\n').includes(token), 'never printed');
  assert.equal(server.calls[0]!.headers.Authorization, `Bearer ${token}`);
});

test('init --token-stdin rejects junk, a missing --project, and tokens not valid for the project, without saving', async () => {
  const { ctx } = makeContext({ readStdin: async () => 'not a token' });
  assert.equal(await main(['init', '--api-url', API, '--project', '5', '--token-stdin'], ctx), 2);
  const noProject = makeContext({ readStdin: async () => '12|' + 'b'.repeat(40) });
  assert.equal(await main(['init', '--api-url', API, '--token-stdin'], noProject.ctx), 2);

  const refused = fakeFetch({ 'GET /api/v1/projects/5': () => ({ status: 403, body: { message: 'This action is unauthorized.' } }) });
  const bad = makeContext({ fetch: refused.impl, readStdin: async () => '12|' + 'b'.repeat(40) });
  assert.equal(await main(['init', '--api-url', API, '--project', '5', '--token-stdin'], bad.ctx), 1);
  assert.ok(!existsSync(join(bad.ctx.env.UNSEEN_CONFIG_DIR!, 'credentials.json')));
});

test('each project keeps its own token: linking a second project never breaks the first', async () => {
  const tokenA = '21|' + 'A'.repeat(40);
  const tokenB = '22|' + 'B'.repeat(40);
  const project = (id: number) => () => ({ body: { data: { ...dashboard.project, id, name: `Project ${id}` } } });
  const server = fakeFetch({
    'GET /api/v1/me': () => ({ body: { data: { id: 1, name: 'Dev', email: 'dev@example.com' } } }),
    'GET /api/v1/projects/5': project(5),
    'GET /api/v1/projects/6': project(6),
  });
  const shared = makeContext().ctx.env; // one computer: one credentials file
  const repoA = makeContext({ fetch: server.impl, readStdin: async () => tokenA });
  const repoB = makeContext({ fetch: server.impl, readStdin: async () => tokenB });
  repoA.ctx.env = shared;
  repoB.ctx.env = shared;

  assert.equal(await main(['init', '--api-url', API, '--project', '5', '--token-stdin'], repoA.ctx), 0);
  assert.equal(await main(['init', '--api-url', API, '--project', '6', '--token-stdin'], repoB.ctx), 0);

  const saved = JSON.parse(readFileSync(join(shared.UNSEEN_CONFIG_DIR!, 'credentials.json'), 'utf8')).tokens;
  assert.equal(saved[`${API}#project=5`], tokenA);
  assert.equal(saved[`${API}#project=6`], tokenB);

  server.calls.length = 0;
  assert.equal(await main(['api', 'GET', '/projects/5'], repoA.ctx), 0);
  assert.equal(await main(['api', 'GET', '/projects/6'], repoB.ctx), 0);
  assert.equal(server.calls[0]!.headers.Authorization, `Bearer ${tokenA}`, 'repo A still uses its own token');
  assert.equal(server.calls[1]!.headers.Authorization, `Bearer ${tokenB}`);
});

test('a token from signing in (not tied to a project) is still used for any linked project', async () => {
  const server = serverRoutes();
  const { ctx } = makeContext({ fetch: server.impl });
  writeFileSync(join(ctx.env.UNSEEN_CONFIG_DIR!, 'credentials.json'), JSON.stringify({ tokens: { [API]: '30|' + 'S'.repeat(40) } }));
  writeProjectConfig(ctx.cwd, { apiUrl: API, projectId: 5 });

  assert.equal(await main(['api', 'GET', '/projects/5'], ctx), 0);
  assert.equal(server.calls[0]!.headers.Authorization, 'Bearer 30|' + 'S'.repeat(40));
});

test('status shows the roadmap with the current phase, and includes it in --json', async () => {
  const { ctx, out } = linked(serverRoutes().impl);
  assert.equal(await main(['status'], ctx), 0);
  assert.match(out.join('\n'), /Roadmap:\n  M1 Foundation: done, 2\/2 done \(100%\), 1\/1 linked features verified\n→ M2 Reports: in progress, 1\/2 done \(50%\), target 2026-11-01/);

  const json = linked(serverRoutes().impl);
  assert.equal(await main(['status', '--json'], json.ctx), 0);
  const data = JSON.parse(json.out.join('\n'));
  assert.equal(data.roadmap.current_phase, 'M2');
  assert.deepEqual(data.roadmap.phases[1].open_items, ['Report UI']);
});

test('roadmap sync turns checklist strings and feature names into the API payload', async () => {
  const server = fakeFetch({
    'GET /api/v1/projects/5/features': () => ({ body: { data: features, meta: { last_page: 1 } } }),
    'PUT /api/v1/projects/5/roadmap': () => ({ body: { data: roadmapData } }),
  });
  const { ctx, out } = linked(server.impl);
  writeFileSync(join(ctx.cwd, 'roadmap.json'), JSON.stringify({ phases: [
    { key: 'M1', name: 'Foundation', items: ['[x] Sign in', '- [ ] Invite colleagues', 'Plain open item',
      { text: 'Password reset flow', done: true, feature: 'password reset' }] },
    { key: 'M2', name: 'Reports', status: 'blocked', target_date: '2026-11-01', description: 'Uses api_key=supersecret123' },
  ] }));

  assert.equal(await main(['roadmap', 'sync', '--file', 'roadmap.json'], ctx), 0);

  const put = server.calls.find((c) => c.method === 'PUT')!;
  assert.deepEqual(put.body, { phases: [
    { key: 'M1', name: 'Foundation', description: null, status: null, target_date: null, items: [
      { text: 'Sign in', done: true }, { text: 'Invite colleagues', done: false }, { text: 'Plain open item', done: false },
      { text: 'Password reset flow', done: true, feature_id: features[0]!.id },
    ] },
    { key: 'M2', name: 'Reports', description: 'Uses api_key=[REDACTED]', status: 'blocked', target_date: '2026-11-01', items: [] },
  ] });
  assert.match(out.join('\n'), /Roadmap synced: 2 phases, 3\/4 items done/);
});

test('roadmap sync explains bad input and sends nothing', async () => {
  const server = fakeFetch({ 'GET /api/v1/projects/5/features': () => ({ body: { data: features, meta: { last_page: 1 } } }) });
  const { ctx, err } = linked(server.impl);
  for (const [json, message] of [
    ['{"nope": 1}', /needs a "phases" array/],
    ['[{"name": "No key"}]', /"key" is required/],
    ['[{"key": "M1", "name": "X", "status": "shipped"}]', /"status" must be one of/],
    ['[{"key": "M1", "name": "X", "items": [{"text": "Y", "feature": "Unknown thing"}]}]', /no feature named "Unknown thing"/],
    ['not json', /not valid JSON/],
  ] as const) {
    writeFileSync(join(ctx.cwd, 'r.json'), json);
    assert.equal(await main(['roadmap', 'sync', '--file', 'r.json'], ctx), 1, json);
    assert.match(err.join('\n'), message);
  }
  assert.ok(!server.calls.some((c) => c.method === 'PUT'));
  assert.equal(await main(['roadmap', 'publish'], ctx), 2);
});

test('roadmap show prints phases; --dry-run prints the payload without sending', async () => {
  const shown = linked(serverRoutes().impl);
  assert.equal(await main(['roadmap', 'show'], shown.ctx), 0);
  assert.match(shown.out.join('\n'), /→ M2 Reports: in progress/);

  const server = fakeFetch({});
  const dry = linked(server.impl);
  writeFileSync(join(dry.ctx.cwd, 'r.json'), '[{"key": "M1", "name": "Foundation", "items": ["[x] Done"]}]');
  assert.equal(await main(['roadmap', 'sync', '--file', 'r.json', '--dry-run'], dry.ctx), 0);
  assert.equal(JSON.parse(dry.out.join('\n')).phases[0].items[0].done, true);
  assert.equal(server.calls.length, 0);
});

test('status marks open items waiting on the client or the team', async () => {
  const data = {
    ...dashboard,
    summary: { ...dashboard.summary, unresolved_waiting_on: { client: 1, team: 0, agent: 1, unsorted: 1 } },
    unresolved: [
      { id: 31, text: 'SingPass', waiting_on: 'client', decision_note: 'Client to provide SingPass access', report_id: 9, reported_at: '2026-10-01T00:00:00Z' },
      { id: 32, text: 'Fix 500', waiting_on: 'agent', decision_note: 'Validate ids', report_id: null, reported_at: '2026-10-01T00:00:00Z' },
      { id: 33, text: 'Mixed gaps', waiting_on: null, decision_note: null, report_id: null, reported_at: '2026-10-01T00:00:00Z' },
    ],
  };
  const fetch = fakeFetch({
    'GET /api/v1/projects/5/dashboard': () => ({ body: { data } }),
    'GET /api/v1/projects/5/features': () => ({ body: { data: [], meta: { last_page: 1 } } }),
    'GET /api/v1/projects/5/roadmap': () => ({ body: { data: { synced_at: null, phases: [] } } }),
  });
  const { ctx, out } = linked(fetch.impl);
  assert.equal(await main(['status'], ctx), 0);
  const text = out.join('\n');
  assert.match(text, /#31 \[waiting on the client\] Client to provide SingPass access — SingPass \(from report #9\)/);
  assert.match(text, /#32 Fix 500/);
  assert.match(text, /#33 \[not sorted\] Mixed gaps/);
  assert.match(text, /Don't work on items waiting on the client \(1\) or the team \(0\)/);
});
