import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { Feature, Mismatch } from '../api.ts';
import { linkedProject, UsageError, type Context } from '../context.ts';
import { redactPersonalData, redactSecrets } from '../redact.ts';
import { PayloadError } from '../report-payload.ts';

export interface MismatchOptions {
  action: string;
  args: string[];
  file?: string;
  status?: string;
  note?: string;
  json?: boolean;
  dryRun?: boolean;
}

const FIELDS = ['key', 'reference', 'title', 'feature', 'feature_id', 'prd_says', 'design_says', 'built_to', 'built_note', 'impact', 'decide_by'];
const BUILT_TO = ['design', 'prd', 'neither', 'not_built'];
const USAGE = `Usage:
  unseen mismatch list [--status open|decided|applied] [--json]
  unseen mismatch show <id> [--json]
  unseen mismatch add --file <path|-> [--dry-run]     (one object or a list)
  unseen mismatch applied <id> --note "<what you changed>"`;

const label = (v: string) => v.replace(/_/g, ' ');

function resolveFeature(ref: unknown, where: string, features: Feature[]): number {
  if (typeof ref === 'number' || /^#?\d+$/.test(String(ref))) {
    const id = Number(String(ref).replace('#', ''));
    if (!features.some((f) => f.id === id)) throw new PayloadError(`${where}: no feature with id ${id} in this project.`);
    return id;
  }
  const matches = features.filter((f) => f.name.trim().toLowerCase() === String(ref).trim().toLowerCase());
  if (matches.length !== 1) {
    throw new PayloadError(matches.length
      ? `${where}: "${String(ref)}" matches several features (ids ${matches.map((f) => f.id).join(', ')}). Use the id.`
      : `${where}: no feature named "${String(ref)}". Run "unseen status" to list features, or leave "feature" out.`);
  }
  return matches[0]!.id;
}

/** Validates the agent's JSON and turns feature names into ids. */
export function buildMismatches(input: unknown, features: Feature[]): Record<string, unknown>[] {
  const list = Array.isArray(input) ? input : [input];
  return list.map((raw, i) => {
    const where = Array.isArray(input) ? `[${i}]` : 'mismatch';
    if (!raw || typeof raw !== 'object' || Array.isArray(raw)) throw new PayloadError(`${where}: each mismatch must be an object.`);
    const m = raw as Record<string, unknown>;
    const unknown = Object.keys(m).filter((k) => !FIELDS.includes(k));
    if (unknown.length) {
      const hint = unknown.some((k) => k.startsWith('decision')) ? ' Agents never decide mismatches: people decide them in Unseen.' : '';
      throw new PayloadError(`${where}: unknown field(s) ${unknown.join(', ')}.${hint}`);
    }
    for (const f of ['title', 'prd_says', 'design_says']) {
      if (typeof m[f] !== 'string' || !(m[f] as string).trim()) throw new PayloadError(`${where}: "${f}" is required.`);
    }
    if (m.built_to !== undefined && !BUILT_TO.includes(String(m.built_to))) throw new PayloadError(`${where}: "built_to" must be one of ${BUILT_TO.join(', ')}.`);
    if (m.decide_by !== undefined && !['team', 'client'].includes(String(m.decide_by))) throw new PayloadError(`${where}: "decide_by" must be team or client.`);
    const out: Record<string, unknown> = { ...m };
    if (m.feature !== undefined) {
      out.feature_id = resolveFeature(m.feature, where, features);
      delete out.feature;
    }
    return out;
  });
}

/**
 * PRD vs design mismatches. The agent records them and, once a person has decided one in Unseen,
 * carries out the decision and marks it applied. Agents can't decide.
 */
export async function mismatch(opts: MismatchOptions, ctx: Context): Promise<number> {
  const { config, client, token } = linkedProject(ctx);
  const pid = config.projectId;
  const [id, ...rest] = opts.args;
  const page = `${config.apiUrl}/projects/${pid}/mismatches`;

  switch (opts.action) {
    case 'list': {
      if (opts.args.length) throw new UsageError(USAGE);
      if (opts.status && !['open', 'decided', 'applied'].includes(opts.status)) throw new UsageError('--status must be open, decided or applied.');
      const res = await client.request<{ data: Mismatch[] }>('GET', `/projects/${pid}/mismatches?per_page=100${opts.status ? `&status=${opts.status}` : ''}`);
      if (opts.json) {
        ctx.out(JSON.stringify(res.data, null, 2));
        return 0;
      }
      ctx.out(res.data.length ? `${res.data.length} mismatches (${page}):` : `No mismatches${opts.status ? ` ${opts.status}` : ''}.`);
      res.data.forEach((m) => ctx.out(mismatchLine(m)));
      return 0;
    }

    case 'show': {
      if (!id || rest.length || !/^#?\d+$/.test(id)) throw new UsageError(USAGE);
      const m = (await client.request<{ data: Mismatch }>('GET', `/mismatches/${id.replace('#', '')}`)).data;
      if (opts.json) {
        ctx.out(JSON.stringify(m, null, 2));
        return 0;
      }
      ctx.out(mismatchLine(m));
      ctx.out(`  PRD says:    ${m.prd_says}`);
      ctx.out(`  Design says: ${m.design_says}`);
      ctx.out(`  Built:       ${label(m.built_to)}${m.built_note ? ` (${m.built_note})` : ''}`);
      if (m.impact) ctx.out(`  Impact:      ${m.impact}`);
      return 0;
    }

    case 'add': {
      if (opts.args.length || !opts.file) throw new UsageError(`unseen mismatch add needs --file <path|->.\n${USAGE}`);
      const text = opts.file === '-' ? await ctx.readStdin() : readFileSync(resolve(ctx.cwd, opts.file), 'utf8');
      let input: unknown;
      try {
        input = JSON.parse(text);
      } catch (error) {
        throw new PayloadError(`The mismatch JSON is not valid: ${(error as Error).message}`);
      }
      const needsFeatures = JSON.stringify(input ?? null).includes('"feature"');
      const items = buildMismatches(input, needsFeatures ? await client.features(pid) : []);
      const secrets = redactSecrets(items, [token]);
      const personal = redactPersonalData(secrets.value);
      if (secrets.redacted.length) ctx.err(`Masked what looked like secrets in: ${secrets.redacted.join(', ')}.`);
      if (personal.redacted.length) ctx.err(`Masked emails, phone or ID numbers in: ${personal.redacted.join(', ')}.`);
      if (opts.dryRun) {
        ctx.out(JSON.stringify(personal.value, null, 2));
        ctx.err('Dry run: nothing was sent.');
        return 0;
      }
      for (const item of personal.value) {
        const saved = await client.request<{ data: Mismatch }>('POST', `/projects/${pid}/mismatches`, item);
        ctx.out(mismatchLine(saved.data));
      }
      ctx.out(`Recorded ${personal.value.length} ${personal.value.length === 1 ? 'mismatch' : 'mismatches'} (same key = updated, not duplicated). People decide them at ${page}`);
      return 0;
    }

    case 'applied': {
      if (!id || rest.length || !/^#?\d+$/.test(id)) throw new UsageError(USAGE);
      const { value } = redactSecrets({ note: opts.note }, [token]);
      const m = (await client.request<{ data: Mismatch }>('POST', `/mismatches/${id.replace('#', '')}/applied`, value)).data;
      ctx.out(`Mismatch #${m.id} marked applied: ${m.title}`);
      return 0;
    }

    default:
      throw new UsageError(USAGE);
  }
}

export function mismatchLine(m: Mismatch): string {
  const state = m.status === 'open'
    ? `needs ${m.decide_by === 'client' ? 'the client' : 'your'} decision`
    : m.status === 'decided' ? `decided: ${label(m.decision ?? '')}, to apply` : 'applied';
  return `  #${m.id} ${m.title} [${state}; built to ${label(m.built_to)}]${m.feature ? ` feature #${m.feature.id}` : ''}`
    + (m.status === 'decided' && m.next_action ? `\n      Next: ${m.next_action}${m.decision_note ? ` Note: ${m.decision_note}` : ''}` : '');
}
