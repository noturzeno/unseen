import assert from 'node:assert/strict';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { test } from 'node:test';
import { cleanHeading, isPrivateDoc, parsePhases, parseRequirements, statusFromText } from '../src/scan/docs.ts';
import { draftRoadmap, scanRepository } from '../src/scan/scanner.ts';
import { main } from '../src/main.ts';
import { makeContext, tempDir } from './helpers.ts';

function repo(files: Record<string, string>): string {
  const root = tempDir();
  for (const [path, content] of Object.entries(files)) {
    mkdirSync(dirname(join(root, path)), { recursive: true });
    writeFileSync(join(root, path), content);
  }
  return root;
}

// Synthetic docs in the shapes documented projects use (PRD, implementation matrix, plan, task list).
const PRD = `# Shop PRD
## 4. Functional Requirements
### 4.2 Checkout
| Ref | Requirement |
| --- | --- |
| CHK-01 | Customers shall pay by card |
| CHK-02 | Customers shall apply **discount** codes ([spec](x.md)) |

### 4.3 Permissions
| Function | Guest | Admin |
| --- | --- | --- |
| Refund | No | Yes |
`;

const MATRIX = `# Implementation matrix
| Status | Means |
| --- | --- |
| 🟢 **Done** | Built |

## CHK — Checkout  (🟢1 · 🟡1)
| Req | Requirement | Status | Key files | Note |
| --- | --- | --- | --- | --- |
| CHK-01 | Card payments | 🟢 Done | app/Pay.php | |
| CHK-02 | Discount codes | 🟡 Partial | | stacking missing |
| CHK-03 | Gift cards | ⚪ To do | | |
| CHK-04 | Wallets | 🔴 Blocked | | waiting on provider |
`;

const TASKS = `# Next steps
| # | Item | Status |
| --- | --- | --- |
| S1 | Rotate keys | Open |
| 2 | Update copy | Done |
`;

const PLAN = `# Plan
## Phase 1 — Checkout (CHK-01–CHK-04) · M
| Page | Block | Status |
| --- | --- | --- |
| Cart | Totals | ✅ Done |
| Cart | Coupons | Todo |
- [x] Card form
- [ ] Wallet button

## Phase 2 — Accounts · S
Notes only.

## Other section
| Page | Status |
| --- | --- |
| Ignored | Done |
`;

test('status words and emoji map to implementation statuses, never verified', () => {
  assert.equal(statusFromText('🟢 **Done**'), 'implemented');
  assert.equal(statusFromText('🔵 Base'), 'implemented');
  assert.equal(statusFromText('🟡 Partial'), 'in_progress');
  assert.equal(statusFromText('🔴 Blocked'), 'blocked');
  assert.equal(statusFromText('⚪ To do'), 'planned');
  assert.equal(statusFromText('Not started'), 'planned');
  assert.equal(statusFromText('In progress'), 'in_progress');
  assert.equal(statusFromText('Descoped'), 'not_implemented');
  assert.equal(statusFromText('Not implemented'), 'not_implemented');
  assert.equal(statusFromText('Complete'), 'implemented');
  assert.equal(statusFromText(''), null);
  assert.equal(statusFromText('see note'), null);
});

test('headings become clean categories', () => {
  assert.equal(cleanHeading('CHK — Checkout  (🟢1 · 🟡1)'), 'Checkout');
  assert.equal(cleanHeading('4.2 Checkout'), 'Checkout');
  assert.equal(cleanHeading('Phase 1 — Design foundation · L'), 'Phase 1 — Design foundation');
});

test('requirement tables give rows with id, status and category; task and permission tables are skipped', () => {
  const prd = parseRequirements(PRD);
  assert.deepEqual(prd.map((r) => [r.id, r.text, r.category, r.rank]), [
    ['CHK-01', 'Customers shall pay by card', 'Checkout', 3],
    ['CHK-02', 'Customers shall apply discount codes (spec)', 'Checkout', 3],
  ]);

  const matrix = parseRequirements(MATRIX);
  assert.deepEqual(matrix.map((r) => [r.id, r.status, r.category]), [
    ['CHK-01', 'implemented', 'Checkout'], ['CHK-02', 'in_progress', 'Checkout'],
    ['CHK-03', 'planned', 'Checkout'], ['CHK-04', 'blocked', 'Checkout'],
  ]);

  assert.deepEqual(parseRequirements(TASKS), []);
});

test('scan merges one requirement across docs: PRD wording, matrix status, archive only as fallback', () => {
  const root = repo({
    'docs/PRD.md': PRD,
    'docs/IMPLEMENTATION_MATRIX.md': MATRIX,
    'docs/archive/OLD_STATUS.md': '| Ref | Requirement | Verdict |\n| --- | --- | --- |\n| CHK-01 | Old wording | ❌ Missing |\n| CHK-09 | Only in archive | Done |\n',
    'docs/NEXT_STEPS.md': TASKS,
    'docs/figma-access.local.md': '| Ref | Requirement |\n| --- | --- |\n| SEC-01 | token abc |\n',
    'app/Http/Controllers/InvoiceController.php': '<?php',
  });

  const { candidates } = scanRepository(root);
  const byKey = new Map(candidates.map((c) => [c.key, c]));

  const chk1 = byKey.get('req-chk-01')!;
  assert.equal(chk1.name, 'Customers shall pay by card'); // PRD wording, not the matrix's short label
  assert.equal(chk1.status, 'implemented'); // from the current matrix, not the archive's "Missing"
  assert.equal(chk1.category, 'Checkout');
  assert.deepEqual(chk1.evidence.map((e) => e.path).sort(), ['docs/IMPLEMENTATION_MATRIX.md', 'docs/PRD.md', 'docs/archive/OLD_STATUS.md']);
  assert.equal(chk1.evidence.find((e) => e.path === 'docs/IMPLEMENTATION_MATRIX.md')!.detail, 'CHK-01 · status: 🟢 Done · Checkout');

  assert.equal(byKey.get('req-chk-02')!.status, 'in_progress');
  assert.equal(byKey.get('req-chk-09')!.status, 'implemented'); // archive is the only source
  assert.ok(!byKey.has('req-sec-01'), '.local. docs are never read');
  assert.ok(![...byKey.keys()].some((k) => k.startsWith('req-s1') || k.includes('rotate')), 'task lists are not features');

  // Documented requirements come before code-derived candidates.
  assert.ok(candidates.findIndex((c) => c.key === 'invoice') > candidates.findIndex((c) => c.key === 'req-chk-04'));
});

test('phases and their status tables and checklists become a roadmap draft', () => {
  const phases = parsePhases(PLAN);
  assert.deepEqual(phases.map((p) => [p.key, p.name, p.description]), [
    ['phase-1', 'Checkout', 'Covers CHK-01–CHK-04'],
    ['phase-2', 'Accounts', null],
  ]);
  assert.deepEqual(phases[0]!.items, [
    { text: 'Cart – Totals', done: true },
    { text: 'Cart – Coupons', done: false },
    { text: 'Card form', done: true },
    { text: 'Wallet button', done: false },
  ]);

  const root = repo({ 'docs/PLAN.md': PLAN, 'docs/archive/OLD_PLAN.md': PLAN + '\n## Phase 3 — Extra\n', 'README.md': '# Shop' });
  const draft = draftRoadmap(root);
  assert.equal(draft.source, 'docs/PLAN.md'); // current doc wins over a bigger archived one
  assert.deepEqual(draft.others, [{ path: 'docs/archive/OLD_PLAN.md', phases: 3 }]);
});

test('unseen roadmap draft prints sync-ready JSON and sends nothing', async () => {
  const { ctx, out, err } = makeContext({ cwd: repo({ 'docs/PLAN.md': PLAN }) });
  assert.equal(await main(['roadmap', 'draft'], ctx), 0);
  const draft = JSON.parse(out.join('\n'));
  assert.deepEqual(draft.phases[0], {
    key: 'phase-1', name: 'Checkout', description: 'Covers CHK-01–CHK-04',
    items: ['[x] Cart – Totals', '[ ] Cart – Coupons', '[x] Card form', '[ ] Wallet button'],
  });
  assert.match(err.join('\n'), /Drafted 2 phases from docs\/PLAN.md/);

  const empty = makeContext({ cwd: repo({ 'README.md': '# Nothing planned' }) });
  assert.equal(await main(['roadmap', 'draft'], empty.ctx), 0);
  assert.deepEqual(JSON.parse(empty.out.join('\n')), { phases: [] });
});

test('private-looking doc names are recognised', () => {
  for (const p of ['docs/figma-access.local.txt', 'docs/secrets.md', 'docs/prod.env.md', 'credentials.md']) assert.ok(isPrivateDoc(p), p);
  for (const p of ['docs/PRD.md', 'docs/deployment/ENVIRONMENT.md', 'README.md']) assert.ok(!isPrivateDoc(p), p);
});
