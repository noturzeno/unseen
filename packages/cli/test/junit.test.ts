import assert from 'node:assert/strict';
import { execFileSync } from 'node:child_process';
import { writeFileSync } from 'node:fs';
import { join } from 'node:path';
import { test } from 'node:test';
import { hasUncommittedChanges } from '../src/git.ts';
import { JunitError, parseJunit } from '../src/junit.ts';
import { tempDir } from './helpers.ts';

test('parses PHPUnit JUnit output', () => {
  const xml = `<?xml version="1.0" encoding="UTF-8"?>
<testsuites>
  <testsuite name="Feature" tests="4">
    <testcase name="test_list" class="Tests\\Feature\\InvoiceTest" classname="Tests.Feature.InvoiceTest" file="/repo/tests/Feature/InvoiceTest.php" line="12" assertions="2" time="0.01"/>
    <testcase name="test_total" class="Tests\\Feature\\InvoiceTest" classname="Tests.Feature.InvoiceTest" file="/repo/tests/Feature/InvoiceTest.php">
      <failure type="PHPUnit\\Framework\\ExpectationFailedException"><![CDATA[Tests\\Feature\\InvoiceTest::test_total
Failed asserting that 9 is identical to 10 &amp; more.]]></failure>
    </testcase>
    <testcase name="test_boom" classname="Tests.Feature.InvoiceTest"><error message="Undefined variable $x">trace</error></testcase>
    <testcase name="test_later" classname="Tests.Feature.InvoiceTest"><skipped/></testcase>
  </testsuite>
</testsuites>`;
  const cases = parseJunit(xml);

  assert.equal(cases.length, 4);
  assert.deepEqual(cases[0], { id: 'Tests\\Feature\\InvoiceTest::test_list', outcome: 'passed', file: '/repo/tests/Feature/InvoiceTest.php' });
  assert.equal(cases[1]!.outcome, 'failed');
  assert.equal(cases[1]!.message, 'Tests\\Feature\\InvoiceTest::test_total');
  assert.equal(cases[2]!.id, 'Tests.Feature.InvoiceTest::test_boom', 'falls back to classname');
  assert.equal(cases[2]!.outcome, 'error');
  assert.equal(cases[2]!.message, 'Undefined variable $x');
  assert.equal(cases[3]!.outcome, 'skipped');
});

test('parses Jest / pytest style output and decodes entities', () => {
  const cases = parseJunit(`<testsuites><testsuite name="invoices">
    <testcase classname="invoices &gt; list" name="shows &quot;all&quot; invoices" time="0.1"></testcase>
    <testcase classname="tests.test_invoice" name="test_x" file="tests/test_invoice.py"><failure message="assert 1 == 2"/></testcase>
    <testcase name="no class"/>
  </testsuite></testsuites>`);

  assert.equal(cases[0]!.id, 'invoices > list::shows "all" invoices');
  assert.equal(cases[1]!.message, 'assert 1 == 2');
  assert.equal(cases[1]!.file, 'tests/test_invoice.py');
  assert.equal(cases[2]!.id, 'no class');
});

test('rejects files that are not JUnit', () => {
  assert.throws(() => parseJunit('{"tests": []}'), JunitError);
});

test('detects uncommitted changes but ignores the given report files', () => {
  const dir = tempDir();
  const git = (...args: string[]) => execFileSync('git', args, { cwd: dir, stdio: 'ignore' });
  git('init', '-q');
  writeFileSync(join(dir, 'a.txt'), 'a');
  git('add', '.');
  git('-c', 'user.email=t@example.com', '-c', 'user.name=T', 'commit', '-qm', 'init');

  assert.equal(hasUncommittedChanges(dir), false);
  writeFileSync(join(dir, 'junit.xml'), '<testsuites/>');
  assert.equal(hasUncommittedChanges(dir, ['junit.xml']), false);
  assert.equal(hasUncommittedChanges(dir), true);
  writeFileSync(join(dir, 'a.txt'), 'changed');
  assert.equal(hasUncommittedChanges(dir, ['junit.xml']), true);
  assert.equal(hasUncommittedChanges(tempDir()), null);
});
