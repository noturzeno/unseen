import assert from 'node:assert/strict';
import { mkdirSync, statSync, writeFileSync, chmodSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { ConfigError, findProjectConfig, normalizeApiUrl, readToken, saveToken, writeProjectConfig } from '../src/config.ts';
import { tempDir } from './helpers.ts';

test('normalizeApiUrl accepts https and local http, strips trailing slash and /api/v1', () => {
  assert.equal(normalizeApiUrl('https://unseen.example.com/'), 'https://unseen.example.com');
  assert.equal(normalizeApiUrl('https://example.com/tf/api/v1'), 'https://example.com/tf');
  assert.equal(normalizeApiUrl('http://127.0.0.1:8000'), 'http://127.0.0.1:8000');
  assert.equal(normalizeApiUrl('http://unseen.test'), 'http://unseen.test');
});

test('normalizeApiUrl refuses plain http to remote hosts and embedded credentials', () => {
  assert.throws(() => normalizeApiUrl('http://unseen.example.com'), ConfigError);
  assert.throws(() => normalizeApiUrl('https://user:pass@example.com'), ConfigError);
  assert.throws(() => normalizeApiUrl('ftp://example.com'), ConfigError);
  assert.throws(() => normalizeApiUrl('not a url'), ConfigError);
});

test('findProjectConfig walks up from subdirectories', () => {
  const root = tempDir();
  writeProjectConfig(root, { apiUrl: 'http://127.0.0.1:8000', projectId: 7 });
  const nested = join(root, 'src', 'deep');
  mkdirSync(nested, { recursive: true });

  assert.deepEqual(findProjectConfig(nested)?.config, { apiUrl: 'http://127.0.0.1:8000', projectId: 7 });
  assert.equal(findProjectConfig(tempDir()), null);
});

test('findProjectConfig rejects malformed files', () => {
  const dir = tempDir();
  writeFileSync(join(dir, '.unseen.json'), '{"apiUrl": "http://127.0.0.1", "projectId": "x"}');
  assert.throws(() => findProjectConfig(dir), /projectId/);
});

test('tokens are saved with 0600 permissions and read back per URL', () => {
  const env = { UNSEEN_CONFIG_DIR: tempDir() };
  const path = saveToken('http://127.0.0.1:8000', '1|abc', env);

  if (process.platform !== 'win32') assert.equal(statSync(path).mode & 0o777, 0o600);
  assert.deepEqual(readToken('http://127.0.0.1:8000', env), { token: '1|abc', source: 'file', warnings: [] });
  assert.equal(readToken('https://other.example.com', env).token, null);
});

test('UNSEEN_TOKEN takes precedence over the credentials file', () => {
  const env: Record<string, string> = { UNSEEN_CONFIG_DIR: tempDir() };
  saveToken('http://127.0.0.1:8000', 'from-file', env);
  env.UNSEEN_TOKEN = 'from-env';

  assert.deepEqual(readToken('http://127.0.0.1:8000', env), { token: 'from-env', source: 'env', warnings: [] });
});

test('loose credential file permissions produce a warning', { skip: process.platform === 'win32' }, () => {
  const env = { UNSEEN_CONFIG_DIR: tempDir() };
  const path = saveToken('http://127.0.0.1:8000', 't', env);
  chmodSync(path, 0o644);

  assert.match(readToken('http://127.0.0.1:8000', env).warnings[0] ?? '', /chmod 600/);
});
