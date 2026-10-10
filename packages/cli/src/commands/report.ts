import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Feature } from '../api.ts';
import { linkedProject, UsageError, type Context } from '../context.ts';
import { gitInfo } from '../git.ts';
import { redactSecrets } from '../redact.ts';
import { buildReportPayload, parseReportJson } from '../report-payload.ts';

export interface ReportOptions {
  file?: string;
  summary?: string;
  agent?: string;
  dryRun?: boolean;
  git?: boolean;
  json?: boolean;
}

/**
 * Submits a structured implementation report. Input is JSON from --file (or "-" for stdin,
 * or piped stdin), and/or a quick --summary. Likely secrets are masked before sending.
 */
export async function report(opts: ReportOptions, ctx: Context): Promise<number> {
  let input: Record<string, unknown>;
  if (opts.file === '-' || (!opts.file && !opts.summary && !ctx.interactive)) {
    input = parseReportJson(await ctx.readStdin());
  } else if (opts.file) {
    input = parseReportJson(readFileSync(resolve(ctx.cwd, opts.file), 'utf8'));
  } else if (opts.summary) {
    input = {};
  } else {
    throw new UsageError('Give report JSON with --file <path> (or pipe it in), or at least --summary "<text>".');
  }
  if (opts.summary) input.summary = opts.summary;
  if (opts.agent && input.agent === undefined) input.agent = opts.agent;

  const { config, client, token } = linkedProject(ctx);
  const features: Feature[] = Array.isArray(input.features) && input.features.length
    ? await client.features(config.projectId)
    : [];

  const tickets = Array.isArray(input.tickets) && input.tickets.length ? await client.tickets(config.projectId, 'state=all') : [];

  const payload = buildReportPayload(input, features, opts.git === false ? null : gitInfo(ctx.cwd), tickets);
  const { value: clean, redacted } = redactSecrets(payload, [token]);
  if (redacted.length) {
    ctx.err(`Masked what looked like secrets in: ${redacted.join(', ')}. Check the report does not need them.`);
  }

  if (opts.dryRun) {
    ctx.out(JSON.stringify(clean, null, 2));
    ctx.err('Dry run: nothing was sent.');
    return 0;
  }

  const saved = await client.submitReport(config.projectId, clean);

  if (opts.json) {
    ctx.out(JSON.stringify({ ...saved, url: `${config.apiUrl}/reports/${saved.id}` }, null, 2));
    return 0;
  }

  ctx.out(`Report #${saved.id} saved: ${config.apiUrl}/reports/${saved.id}`);
  saved.features.forEach((f) => ctx.out(`  #${f.id} ${f.name}${f.claimed_status ? `: claimed ${f.claimed_status.replace(/_/g, ' ')}` : ''}`));
  (saved.tickets ?? []).forEach((t) => ctx.out(`  ${t.label} ${t.title}${t.to_status ? `: now ${t.to_status.replace(/_/g, ' ')}` : ''}`));
  if (saved.features.length) {
    ctx.out('Reports do not verify features. Only CI test runs (unseen verify) do.');
  }
  return 0;
}
