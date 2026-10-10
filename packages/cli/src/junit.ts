/**
 * Minimal JUnit XML reader. PHPUnit (--log-junit), Jest (jest-junit), Vitest (--reporter=junit)
 * and pytest (--junitxml) all write this format.
 */

export type CaseOutcome = 'passed' | 'failed' | 'error' | 'skipped';

export interface TestCase {
  /** "Class::name" (PHPUnit's `class`, else `classname`) when a class is given, else the test name. */
  id: string;
  file?: string;
  outcome: CaseOutcome;
  message?: string;
}

export class JunitError extends Error {}

const ENTITIES: Record<string, string> = { lt: '<', gt: '>', amp: '&', quot: '"', apos: "'" };

function decode(text: string): string {
  return text
    .replace(/<!\[CDATA\[([\s\S]*?)\]\]>/g, '$1')
    .replace(/&(#x[0-9a-f]+|#\d+|\w+);/gi, (match, entity: string) => {
      if (entity[0] === '#') {
        const code = entity[1]?.toLowerCase() === 'x' ? parseInt(entity.slice(2), 16) : parseInt(entity.slice(1), 10);
        return Number.isFinite(code) ? String.fromCodePoint(code) : match;
      }
      return ENTITIES[entity] ?? match;
    });
}

function attributes(raw: string): Record<string, string> {
  const attrs: Record<string, string> = {};
  for (const m of raw.matchAll(/([\w:.-]+)\s*=\s*(?:"([^"]*)"|'([^']*)')/g)) {
    attrs[m[1]!] = decode(m[2] ?? m[3] ?? '');
  }
  return attrs;
}

function childMessage(body: string, tag: string): string | null {
  const m = body.match(new RegExp(`<${tag}\\b([^>]*?)(?:/>|>([\\s\\S]*?)</${tag}>)`));
  if (!m) return null;
  const attrs = attributes(m[1] ?? '');
  const text = decode(m[2] ?? '').trim();
  return (attrs.message || text.split('\n').find((l) => l.trim()) || tag).trim();
}

export function parseJunit(xml: string): TestCase[] {
  if (!/<testsuites?\b|<testcase\b/.test(xml)) {
    throw new JunitError('This does not look like a JUnit XML report (no <testsuite> or <testcase> found).');
  }

  const cases: TestCase[] = [];
  for (const m of xml.matchAll(/<testcase\b([^>]*?)(?:\/>|>([\s\S]*?)<\/testcase>)/g)) {
    const attrs = attributes(m[1] ?? '');
    const body = m[2] ?? '';
    const name = attrs.name ?? '';
    // PHPUnit writes both: class="Tests\Feature\XTest" (real name) and classname="Tests.Feature.XTest".
    // Prefer the real class name so checks can use the namespace as written in code.
    const cls = attrs.class || attrs.classname || '';
    if (!name && !cls) continue;

    const failure = childMessage(body, 'failure');
    const error = failure === null ? childMessage(body, 'error') : null;
    const skipped = /<skipped\b/.test(body);

    const testCase: TestCase = {
      id: cls ? `${cls}::${name}` : name,
      outcome: failure !== null ? 'failed' : error !== null ? 'error' : skipped ? 'skipped' : 'passed',
    };
    if (attrs.file) testCase.file = attrs.file;
    const message = failure ?? error;
    if (message) testCase.message = message;
    cases.push(testCase);
  }
  return cases;
}
