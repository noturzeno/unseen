import type { Feature } from './api.ts';
import { PayloadError } from './report-payload.ts';

/** Shape sent to PUT /api/v1/projects/{id}/roadmap. */
export interface RoadmapPayload {
  phases: {
    key: string;
    name: string;
    description?: string | null;
    status?: string | null;
    target_date?: string | null;
    items: { text: string; done: boolean; feature_id?: number }[];
  }[];
}

const STATUSES = ['planned', 'in_progress', 'done', 'blocked'];

/**
 * Turns the agent's roadmap JSON into the API payload. Accepts `{ "phases": [...] }` or a bare array.
 * Items may be strings ("[x] Done thing", "[ ] Open thing", "Open thing") or objects with
 * `text`, `done` and `feature` (an id or a feature name, matched case-insensitively).
 */
export function buildRoadmapPayload(input: unknown, features: Feature[]): RoadmapPayload {
  const phases = Array.isArray(input) ? input : (input as { phases?: unknown } | null)?.phases;
  if (!Array.isArray(phases)) throw new PayloadError('Roadmap JSON needs a "phases" array (or be an array of phases).');

  return {
    phases: phases.map((raw, i) => {
      const where = `phases[${i}]`;
      if (!raw || typeof raw !== 'object') throw new PayloadError(`${where}: each phase must be an object.`);
      const phase = raw as Record<string, unknown>;
      const key = String(phase.key ?? '').trim();
      const name = String(phase.name ?? '').trim();
      if (!key) throw new PayloadError(`${where}: "key" is required (a stable id such as "M1" or "phase-2").`);
      if (!name) throw new PayloadError(`${where}: "name" is required.`);
      if (phase.status != null && !STATUSES.includes(String(phase.status))) {
        throw new PayloadError(`${where}: "status" must be one of ${STATUSES.join(', ')}, or left out to work it out from the items.`);
      }
      const items = phase.items ?? [];
      if (!Array.isArray(items)) throw new PayloadError(`${where}: "items" must be an array.`);

      return {
        key,
        name,
        description: typeof phase.description === 'string' ? phase.description : null,
        status: phase.status == null ? null : String(phase.status),
        target_date: typeof phase.target_date === 'string' ? phase.target_date : null,
        items: items.map((item, j) => buildItem(item, `${where}.items[${j}]`, features)),
      };
    }),
  };
}

function buildItem(item: unknown, where: string, features: Feature[]) {
  if (typeof item === 'string') {
    const m = /^\s*(?:[-*]\s*)?\[( |x|X)\]\s*(.*)$/.exec(item);
    const text = (m ? m[2]! : item).trim();
    if (!text) throw new PayloadError(`${where}: empty item.`);
    return { text, done: m ? m[1]!.toLowerCase() === 'x' : false };
  }
  if (!item || typeof item !== 'object') throw new PayloadError(`${where}: give a string or an object with "text".`);

  const obj = item as Record<string, unknown>;
  const text = String(obj.text ?? '').trim();
  if (!text) throw new PayloadError(`${where}: "text" is required.`);
  const out: { text: string; done: boolean; feature_id?: number } = { text, done: obj.done === true };
  if (obj.feature != null) out.feature_id = resolveFeature(obj.feature, where, features);
  return out;
}

function resolveFeature(ref: unknown, where: string, features: Feature[]): number {
  if (typeof ref === 'number') {
    if (!features.some((f) => f.id === ref)) throw new PayloadError(`${where}: no feature with id ${ref} in this project.`);
    return ref;
  }
  const wanted = String(ref).trim().toLowerCase();
  const matches = features.filter((f) => f.name.trim().toLowerCase() === wanted);
  if (matches.length === 0) {
    throw new PayloadError(`${where}: no feature named "${String(ref)}". Record it first with "unseen api POST /projects/<id>/features", or leave "feature" out.`);
  }
  if (matches.length > 1) throw new PayloadError(`${where}: "${String(ref)}" matches several features (ids ${matches.map((f) => f.id).join(', ')}). Use the id.`);
  return matches[0]!.id;
}

/** True when any item refers to a feature, so the feature list needs fetching. */
export function needsFeatures(input: unknown): boolean {
  return JSON.stringify(input ?? null).includes('"feature"');
}
