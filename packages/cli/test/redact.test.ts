import assert from 'node:assert/strict';
import { test } from 'node:test';
import { redactSecrets, redactString } from '../src/redact.ts';

test('masks common token formats', () => {
  const samples = [
    'AKIAABCDEFGHIJKLMNOP',
    'ghp_' + 'a'.repeat(36),
    'xoxb-1234567890-abcdef',
    'sk_live_' + 'a'.repeat(24),
    'sk-ant-' + 'b'.repeat(30),
    'eyJhbGciOiJIUzI1NiJ9.eyJzdWIiOiIxMjM0In0.c2lnbmF0dXJlLXZhbHVl',
    '12|' + 'Z'.repeat(40),
  ];
  for (const secret of samples) {
    const out = redactString(`before ${secret} after`);
    assert.ok(!out.includes(secret), `should mask ${secret}`);
    assert.match(out, /before \[REDACTED\] after/);
  }
});

test('masks private key blocks, auth headers, URL credentials and key=value secrets', () => {
  assert.equal(redactString('-----BEGIN RSA PRIVATE KEY-----\nabc\n-----END RSA PRIVATE KEY-----'), '[REDACTED]');
  assert.equal(redactString('Authorization: Bearer abc.def'), 'Authorization: Bearer [REDACTED]');
  assert.equal(redactString('postgres://app:hunter2@db:5432/x'), 'postgres://[REDACTED]@db:5432/x');
  assert.equal(redactString('DB_PASSWORD=hunter2 and API_KEY: "xyz"'), 'DB_PASSWORD=[REDACTED] and API_KEY: [REDACTED]');
});

test('leaves ordinary text alone', () => {
  const text = 'Added password reset form; tokens expire after 60 minutes. See app/Http/TokenController.php';
  assert.equal(redactString(text), text);
});

test('masks extra known secrets such as the CLI token itself', () => {
  assert.equal(redactString('token is my-cli-token-123', ['my-cli-token-123']), 'token is [REDACTED]');
});

test('redactSecrets walks nested values and reports paths', () => {
  const { value, redacted } = redactSecrets({
    summary: 'ok',
    tests: [{ command: 'API_TOKEN=abc123 npm test', outcome: 'passed' }],
    features: [{ id: 1 }],
  });
  assert.equal(value.tests[0]!.command, 'API_TOKEN=[REDACTED] npm test');
  assert.deepEqual(redacted, ['$.tests[0].command']);
  assert.equal(value.features[0]!.id, 1);
});
