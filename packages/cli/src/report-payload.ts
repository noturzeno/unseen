import type { Feature, Ticket } from './api.ts';
import type { GitInfo } from './git.ts';

export class PayloadError extends Error {}

/** Shape sent to POST /api/v1/projects/{id}/reports. */
export interface ReportPayload {
  summary: string;
  /** What the agent was asked to do, in a sentence (shown under "Recently targeted modules"). */
  prompt?: string | null;
  agent?: string | null;
  confidence?: string | null;
  limitations?: string | null;
  reported_at?: string | null;
  git_branch?: string | null;
  git_commit?: string | null;
  diff_ref?: string | null;
  features?: { id: number; status?: string | null; note?: string | null }[];
  changed_files?: { path: string; change?: string | null }[];
  tests?: { command: string; outcome: string; summary?: string | null }[];
  unresolved?: string[];
  /** Ids of open unresolved items (from "unseen status") that this work resolved. */
  resolves?: number[];
  /** Tickets this work moved or touched: id plus the new status and a note. */
  tickets?: { id: number; status?: string | null; note?: string | null }[];
}

const ALLOWED_KEYS = new Set([
  'summary', 'prompt', 'agent', 'confidence', 'limitations', 'reported_at', 'git_branch', 'git_commit', 'diff_ref',
  'features', 'changed_files', 'tests', 'unresolved', 'resolves', 'tickets',
]);

/** A ticket in a report: its reference ("WEB-142"), "#id" / id, or an object with `id` or `ref`. */
export type TicketRef = number | string | { id?: number; ref?: string; status?: string | null; note?: string | null };

type FeatureRef = number | string | { id?: number; name?: string; status?: string | null; note?: string | null };

export function parseReportJson(text: string): Record<string, unknown> {
  let data: unknown;
  try {
    data = JSON.parse(text);
  } catch (error) {
    throw new PayloadError(`Report input is not valid JSON: ${(error as Error).message}`);
  }
  if (!data || typeof data !== 'object' || Array.isArray(data)) {
    throw new PayloadError('Report input must be a JSON object.');
  }
  return data as Record<string, unknown>;
}

/**
 * Normalise loose report input into the API payload:
 *  - features may be ids, names, or objects with `id` or `name` (names resolve case-insensitively)
 *  - changed_files may be plain path strings
 *  - git branch/commit are filled from the repository when not given
 * Unknown keys are rejected so typos (or attempts to set verification) are caught early.
 */
export function buildReportPayload(input: Record<string, unknown>, features: Feature[], git: GitInfo | null, tickets: Ticket[] = []): ReportPayload {
  const unknown = Object.keys(input).filter((k) => !ALLOWED_KEYS.has(k));
  if (unknown.length) {
    const hint = unknown.some((k) => k.includes('verif'))
      ? ' Reports cannot set verification; a person verifies features in Unseen.'
      : '';
    throw new PayloadError(`Unknown report field(s): ${unknown.join(', ')}.${hint}`);
  }

  if (typeof input.summary !== 'string' || input.summary.trim() === '') {
    throw new PayloadError('A report needs a non-empty "summary".');
  }

  const payload = { ...input, summary: input.summary.trim() } as ReportPayload & Record<string, unknown>;

  if (input.features !== undefined) {
    if (!Array.isArray(input.features)) throw new PayloadError('"features" must be an array.');
    payload.features = (input.features as FeatureRef[]).map((ref, i) => resolveFeature(ref, i, features));
  }

  if (input.changed_files !== undefined) {
    if (!Array.isArray(input.changed_files)) throw new PayloadError('"changed_files" must be an array.');
    payload.changed_files = input.changed_files.map((f) => (typeof f === 'string' ? { path: f } : f));
  }

  if (input.resolves !== undefined) {
    if (!Array.isArray(input.resolves) || !input.resolves.every((id) => Number.isInteger(id) && (id as number) > 0)) {
      throw new PayloadError('"resolves" must be a list of unresolved item ids (numbers from "unseen status").');
    }
  }

  if (input.tickets !== undefined) {
    if (!Array.isArray(input.tickets)) throw new PayloadError('"tickets" must be an array.');
    payload.tickets = (input.tickets as TicketRef[]).map((ref, i) => resolveTicket(ref, i, tickets));
  }

  if (git) {
    payload.git_branch ??= git.branch;
    payload.git_commit ??= git.commit;
  }

  for (const key of Object.keys(payload)) {
    if (payload[key] === null || payload[key] === undefined) delete payload[key];
  }

  return payload;
}

function resolveFeature(ref: FeatureRef, index: number, features: Feature[]): { id: number; status?: string | null; note?: string | null } {
  const where = `features[${index}]`;
  const obj = typeof ref === 'object' && ref !== null ? ref : null;
  const id = typeof ref === 'number' ? ref : obj?.id;
  const name = typeof ref === 'string' ? ref : obj?.name;

  let match: Feature | undefined;
  if (id !== undefined) {
    match = features.find((f) => f.id === id);
    if (!match) throw new PayloadError(`${where}: no feature with id ${id} in this project. Run "unseen status" to list features.`);
  } else if (typeof name === 'string') {
    const wanted = name.trim().toLowerCase();
    const matches = features.filter((f) => f.name.trim().toLowerCase() === wanted);
    if (matches.length === 0) {
      throw new PayloadError(`${where}: no feature named "${name}". Run "unseen status" to list features, or add it in Unseen first.`);
    }
    if (matches.length > 1) {
      throw new PayloadError(`${where}: "${name}" matches several features (ids ${matches.map((f) => f.id).join(', ')}). Use the id.`);
    }
    match = matches[0];
  } else {
    throw new PayloadError(`${where}: give a feature id, a feature name, or an object with "id" or "name".`);
  }

  const out: { id: number; status?: string | null; note?: string | null } = { id: match!.id };
  if (obj?.status) out.status = obj.status;
  if (obj?.note) out.note = obj.note;
  return out;
}

function resolveTicket(ref: TicketRef, index: number, tickets: Ticket[]): { id: number; status?: string | null; note?: string | null } {
  const where = `tickets[${index}]`;
  const obj = typeof ref === 'object' && ref !== null ? ref : null;
  const raw = typeof ref === 'object' && ref !== null ? (ref.id ?? ref.ref) : ref;
  if (raw === undefined || raw === null || String(raw).trim() === '') {
    throw new PayloadError(`${where}: give a ticket reference (e.g. "WEB-142"), an id, or an object with "id" or "ref".`);
  }

  const asId = typeof raw === 'number' ? raw : /^#?(\d+)$/.exec(String(raw).trim())?.[1];
  const match = asId !== undefined
    ? tickets.find((t) => t.id === Number(asId))
    : tickets.find((t) => (t.reference ?? '').toLowerCase() === String(raw).trim().toLowerCase());
  if (!match) throw new PayloadError(`${where}: no ticket "${String(raw)}" in this project. Record it first with "unseen ticket add".`);

  const out: { id: number; status?: string | null; note?: string | null } = { id: match.id };
  if (obj?.status) out.status = obj.status;
  if (obj?.note) out.note = obj.note;
  return out;
}
