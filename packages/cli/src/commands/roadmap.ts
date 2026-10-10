import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Feature, Roadmap, RoadmapPhase } from '../api.ts';
import { linkedProject, UsageError, type Context } from '../context.ts';
import { redactSecrets } from '../redact.ts';
import { PayloadError } from '../report-payload.ts';
import { buildRoadmapPayload, needsFeatures } from '../roadmap-payload.ts';
import { draftRoadmap } from '../scan/scanner.ts';

export interface RoadmapOptions {
  action: string;
  file?: string;
  dryRun?: boolean;
  json?: boolean;
}

const label = (value: string) => value.replace(/_/g, ' ');

/** One line per phase, e.g. "M2 Reports: in progress, 3/6 done (50%), 1/2 linked features verified". */
export function phaseLine(p: RoadmapPhase): string {
  const parts = [label(p.status)];
  if (p.progress.items) parts.push(`${p.progress.done}/${p.progress.items} done (${p.progress.percent}%)`);
  if (p.progress.features) parts.push(`${p.progress.verified}/${p.progress.features} linked features verified`);
  if (p.target_date) parts.push(`target ${p.target_date}`);
  return `${p.key} ${p.name}: ${parts.join(', ')}`;
}

/** The first phase that isn't done: what the team is working towards now. */
export function currentPhase(roadmap: Roadmap): RoadmapPhase | undefined {
  return roadmap.phases.find((p) => p.status !== 'done');
}

/**
 * `unseen roadmap show` prints the project's roadmap; `unseen roadmap sync` replaces it with the
 * plan from --file (or stdin), so Unseen matches the repository's own roadmap.
 */
export async function roadmap(opts: RoadmapOptions, ctx: Context): Promise<number> {
  if (opts.action === 'draft') return draft(ctx);
  if (opts.action !== 'show' && opts.action !== 'sync') {
    throw new UsageError('Usage: unseen roadmap draft | show [--json] | sync --file <path|-> [--dry-run] [--json]');
  }

  const { config, client, token } = linkedProject(ctx);

  if (opts.action === 'show') {
    const current = await client.roadmap(config.projectId);
    print(current, opts, ctx, config.apiUrl, config.projectId);
    return 0;
  }

  const text = opts.file === '-' || (!opts.file && !ctx.interactive) ? await ctx.readStdin()
    : opts.file ? readFileSync(resolve(ctx.cwd, opts.file), 'utf8')
      : undefined;
  if (text === undefined) throw new UsageError('Give the roadmap JSON with --file <path> (or pipe it in).');

  let input: unknown;
  try {
    input = JSON.parse(text);
  } catch (error) {
    throw new PayloadError(`The roadmap is not valid JSON: ${(error as Error).message}`);
  }

  const features: Feature[] = needsFeatures(input) ? await client.features(config.projectId) : [];
  const { value: payload, redacted } = redactSecrets(buildRoadmapPayload(input, features), [token]);
  if (redacted.length) ctx.err(`Masked what looked like secrets in: ${redacted.join(', ')}.`);

  if (opts.dryRun) {
    ctx.out(JSON.stringify(payload, null, 2));
    ctx.err('Dry run: nothing was sent.');
    return 0;
  }

  const saved = await client.syncRoadmap(config.projectId, payload);
  print(saved, opts, ctx, config.apiUrl, config.projectId, 'Roadmap synced');
  return 0;
}

function print(data: Roadmap, opts: RoadmapOptions, ctx: Context, apiUrl: string, projectId: number, heading = 'Roadmap') {
  const url = `${apiUrl}/projects/${projectId}/roadmap`;
  if (opts.json) {
    ctx.out(JSON.stringify({ ...data, url }, null, 2));
    return;
  }
  if (data.phases.length === 0) {
    ctx.out(`${heading}: no phases. ${url}`);
    return;
  }
  const items = data.phases.reduce((n, p) => n + p.progress.items, 0);
  const done = data.phases.reduce((n, p) => n + p.progress.done, 0);
  ctx.out(`${heading}: ${data.phases.length} phases, ${done}/${items} items done. ${url}`);
  const current = currentPhase(data);
  for (const p of data.phases) ctx.out(`${p === current ? '→' : ' '} ${phaseLine(p)}`);
}

/**
 * Prints a roadmap drafted from the repository's plan docs as JSON for "roadmap sync". Reads local files
 * only and sends nothing: the agent checks it (ticks what is built, links features) before syncing.
 */
function draft(ctx: Context): number {
  const result = draftRoadmap(ctx.cwd);
  if (!result.source) {
    ctx.err('No plan found: no Markdown doc has two or more "Phase/Milestone/Sprint/Release N" headings.');
    ctx.out(JSON.stringify({ phases: [] }, null, 2));
    return 0;
  }
  ctx.out(JSON.stringify({
    phases: result.phases.map((p) => ({
      key: p.key,
      name: p.name,
      ...(p.description ? { description: p.description } : {}),
      items: p.items.map((i) => `[${i.done ? 'x' : ' '}] ${i.text}`),
    })),
  }, null, 2));
  ctx.err(`Drafted ${result.phases.length} phases from ${result.source}.`);
  if (result.others.length) ctx.err(`Other plan docs: ${result.others.map((o) => `${o.path} (${o.phases})`).join(', ')}.`);
  ctx.err('Check it before syncing: tick what is already built, link items to features, then run "unseen roadmap sync --file <file>".');
  return 0;
}
