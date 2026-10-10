import { spawnSync } from 'node:child_process';
import { existsSync, readFileSync } from 'node:fs';
import { dirname, relative, resolve } from 'node:path';
import { ApiError, VERSION } from '../api.ts';
import { findProjectConfig } from '../config.ts';
import { linkedProject, UsageError, type Context } from '../context.ts';
import { gitInfo, hasUncommittedChanges } from '../git.ts';
import { parseJunit, type TestCase } from '../junit.ts';
import { redactString } from '../redact.ts';

export interface VerifyOptions {
  junit: string[];
  run?: string;
  dryRun?: boolean;
  json?: boolean;
  git?: boolean;
}

const OUTCOME_LABELS: Record<string, string> = {
  verified: 'verified',
  failed: 'marked failed',
  incomplete: 'incomplete (some checks did not run)',
  passed_not_applied: 'passed but not applied',
  not_covered: 'not covered by this run',
};

/**
 * Sends automated test results (JUnit XML) to Unseen, which applies the verification policy.
 * Needs a token with the `verify` ability (e.g. CI), not the agent's token.
 */
export async function verify(opts: VerifyOptions, ctx: Context): Promise<number> {
  if (!opts.junit.length) throw new UsageError('Pass at least one --junit <report.xml>.');

  const { config, client, token } = linkedProject(ctx);
  const root = dirname(findProjectConfig(ctx.cwd)!.path);

  let runExit = 0;
  if (opts.run) {
    ctx.err(`Running: ${opts.run}`);
    const result = spawnSync(opts.run, { cwd: ctx.cwd, shell: true, stdio: 'inherit', env: { ...process.env, ...ctx.env } as NodeJS.ProcessEnv });
    runExit = result.status ?? 1;
    ctx.err(`Command exited with code ${runExit}.`);
  }

  const files = opts.junit.map((f) => resolve(ctx.cwd, f));
  const cases: TestCase[] = [];
  for (const file of files) {
    if (!existsSync(file)) throw new UsageError(`JUnit report not found: ${file}${opts.run ? ' (did the command write it?)' : ''}`);
    cases.push(...parseJunit(readFileSync(file, 'utf8')));
  }
  if (cases.length === 0) throw new UsageError('The JUnit report(s) contain no test cases.');
  if (cases.length > 20_000) throw new UsageError(`Too many test cases (${cases.length}); the limit is 20,000 per run.`);

  const git = opts.git === false ? null : gitInfo(root);
  const dirty = opts.git === false ? null : hasUncommittedChanges(root, files.map((f) => relative(root, f)));
  if (dirty) ctx.err('Note: there are uncommitted changes, so this run can mark features failed but not verified.');

  const payload = {
    runner: `unseen-cli ${VERSION}`,
    git_branch: git?.branch ?? undefined,
    git_commit: git?.commit ?? undefined,
    git_dirty: dirty ?? false,
    cases: cases.map((c) => ({
      id: c.id.slice(0, 1000),
      file: c.file ? (c.file.startsWith(root) ? relative(root, c.file) : c.file).slice(0, 1024) : undefined,
      outcome: c.outcome,
      message: c.message ? redactString(c.message, [token]).slice(0, 500) : undefined,
    })),
  };

  if (opts.dryRun) {
    const counts = cases.reduce<Record<string, number>>((acc, c) => ({ ...acc, [c.outcome]: (acc[c.outcome] ?? 0) + 1 }), {});
    ctx.out(opts.json ? JSON.stringify(payload, null, 2) : `${cases.length} test cases: ${Object.entries(counts).map(([k, v]) => `${v} ${k}`).join(', ')}`);
    ctx.err('Dry run: nothing was sent.');
    return runExit;
  }

  let summary;
  try {
    summary = await client.submitTestRun(config.projectId, payload);
  } catch (error) {
    if (error instanceof ApiError && error.status === 403) {
      throw new ApiError(403, 'This token cannot submit test results. That needs a token with the "verify" ability, '
        + 'such as a CI token (see the CLI README). Agent tokens from "unseen init" deliberately cannot verify.');
    }
    throw error;
  }

  if (opts.json) {
    ctx.out(JSON.stringify({ ...summary, url: `${config.apiUrl}/test-runs/${summary.id}` }, null, 2));
    return runExit;
  }

  const t = summary.totals;
  ctx.out(`Test run #${summary.id}: ${t.passed} passed, ${t.failed} failed, ${t.skipped} skipped (${t.total} total).`);
  const featureLine = Object.entries(summary.features).map(([k, v]) => `${v} ${OUTCOME_LABELS[k] ?? k}`).join(', ');
  ctx.out(featureLine ? `Features: ${featureLine}.` : 'No features have automated checks yet; add checks on feature pages in Unseen.');
  ctx.out(`${config.apiUrl}/test-runs/${summary.id}`);
  return runExit;
}
