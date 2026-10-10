import assert from 'node:assert/strict';
import { test } from 'node:test';
import type { Feature } from '../src/api.ts';
import { buildReportPayload, parseReportJson, PayloadError } from '../src/report-payload.ts';

const features: Feature[] = [
  { id: 1, name: 'Password reset', description: null, status: 'planned', verification_status: 'not_checked', category: null },
  { id: 2, name: 'Login', description: null, status: 'implemented', verification_status: 'verified', category: null },
  { id: 3, name: 'Dup', description: null, status: 'planned', verification_status: 'not_checked', category: null },
  { id: 4, name: 'dup', description: null, status: 'planned', verification_status: 'not_checked', category: null },
];

test('resolves features by id, name and object, case-insensitively', () => {
  const payload = buildReportPayload({
    summary: ' Done ',
    features: [2, ' password RESET ', { name: 'login', status: 'implemented', note: 'n' }, { id: 1, status: 'in_progress' }],
  }, features, null);

  assert.equal(payload.summary, 'Done');
  assert.deepEqual(payload.features, [
    { id: 2 }, { id: 1 }, { id: 2, status: 'implemented', note: 'n' }, { id: 1, status: 'in_progress' },
  ]);
});

test('unknown or ambiguous features give clear errors', () => {
  assert.throws(() => buildReportPayload({ summary: 'x', features: ['Nope'] }, features, null), /no feature named "Nope"/);
  assert.throws(() => buildReportPayload({ summary: 'x', features: [99] }, features, null), /no feature with id 99/);
  assert.throws(() => buildReportPayload({ summary: 'x', features: ['DUP'] }, features, null), /matches several features \(ids 3, 4\)/);
  assert.throws(() => buildReportPayload({ summary: 'x', features: [{}] }, features, null), PayloadError);
});

test('rejects unknown keys and explains that reports cannot verify', () => {
  assert.throws(() => buildReportPayload({ summary: 'x', verification_status: 'verified' }, features, null), /cannot set verification/);
  assert.throws(() => buildReportPayload({ summary: 'x', sumary: 'typo' }, features, null), /Unknown report field\(s\): sumary/);
});

test('accepts resolves as a list of ids and rejects anything else', () => {
  assert.deepEqual(buildReportPayload({ summary: 'x', resolves: [3, 4] }, features, null).resolves, [3, 4]);
  assert.throws(() => buildReportPayload({ summary: 'x', resolves: ['Rate limiting'] }, features, null), /unresolved item ids/);
  assert.throws(() => buildReportPayload({ summary: 'x', resolves: 3 }, features, null), /unresolved item ids/);
});

test('requires a summary', () => {
  assert.throws(() => buildReportPayload({}, features, null), /summary/);
  assert.throws(() => buildReportPayload({ summary: '   ' }, features, null), /summary/);
});

test('normalises changed_files strings and fills git info only when missing', () => {
  const git = { branch: 'main', commit: 'abc1234' };
  const payload = buildReportPayload({ summary: 'x', changed_files: ['a.ts', { path: 'b.ts', change: 'added' }], git_branch: 'feature/x' }, features, git);

  assert.deepEqual(payload.changed_files, [{ path: 'a.ts' }, { path: 'b.ts', change: 'added' }]);
  assert.equal(payload.git_branch, 'feature/x');
  assert.equal(payload.git_commit, 'abc1234');

  const noGit = buildReportPayload({ summary: 'x' }, features, { branch: null, commit: null });
  assert.equal('git_branch' in noGit, false);
});

test('parseReportJson rejects non-objects', () => {
  assert.throws(() => parseReportJson('[1]'), /JSON object/);
  assert.throws(() => parseReportJson('{nope'), /not valid JSON/);
});

test('passes the prompt the agent was given through', () => {
  const payload = buildReportPayload({ summary: 'Done', prompt: 'Add Apple Pay to checkout' }, features, null);
  assert.equal(payload.prompt, 'Add Apple Pay to checkout');
});
