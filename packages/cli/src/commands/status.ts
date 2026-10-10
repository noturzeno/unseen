import type { Feature } from '../api.ts';
import { linkedProject, type Context } from '../context.ts';
import { currentPhase, phaseLine } from './roadmap.ts';
import { mismatchLine } from './mismatch.ts';
import { ticketLine } from './ticket.ts';

export interface StatusOptions {
  json?: boolean;
}

const label = (value: string) => value.replace(/_/g, ' ');

/**
 * Project context for a developer or coding agent: progress, what needs attention,
 * unresolved work from recent reports, and the feature inventory with ids.
 */
export async function status(opts: StatusOptions, ctx: Context): Promise<number> {
  const { config, client } = linkedProject(ctx);
  const [dashboard, features, roadmap] = await Promise.all([
    client.dashboard(config.projectId), client.features(config.projectId), client.roadmap(config.projectId),
  ]);
  const { project, summary } = dashboard;

  if (opts.json) {
    ctx.out(JSON.stringify({
      project: { id: project.id, name: project.name, description: project.description, url: `${config.apiUrl}/projects/${project.id}` },
      summary,
      needs_attention: dashboard.needs_attention.map((f) => ({ id: f.id, name: f.name, status: f.status, verification_status: f.verification_status })),
      unresolved: dashboard.unresolved,
      mismatches: {
        counts: summary.mismatches ?? null,
        to_apply: (dashboard.mismatches ?? []).filter((m) => m.status === 'decided'),
        waiting_for_decision: (dashboard.mismatches ?? []).filter((m) => m.status === 'open')
          .map((m) => ({ id: m.id, title: m.title, decide_by: m.decide_by, feature_id: m.feature?.id ?? null })),
      },
      tickets: {
        counts: summary.tickets ?? null,
        open: (dashboard.open_tickets ?? []).map((t) => ({
          id: t.id, reference: t.reference, title: t.title, type: t.type, status: t.status,
          feature_id: t.feature?.id ?? null, latest_feedback: t.latest_feedback
            ? { role: t.latest_feedback.author_role, body: t.latest_feedback.body, at: t.latest_feedback.created_at } : null,
        })),
        recent_feedback: (dashboard.recent_feedback ?? []).map((c) => ({
          ticket: c.ticket.reference ?? `#${c.ticket.id}`, role: c.author_role, body: c.body, to_status: c.to_status, at: c.created_at,
        })),
      },
      roadmap: {
        current_phase: currentPhase(roadmap)?.key ?? null,
        synced_at: roadmap.synced_at,
        phases: roadmap.phases.map((p) => ({
          key: p.key, name: p.name, status: p.status, target_date: p.target_date, progress: p.progress,
          open_items: p.items.filter((i) => !i.done).map((i) => i.text),
        })),
      },
      features: features.map((f) => ({
        id: f.id,
        name: f.name,
        category: f.category?.name ?? null,
        status: f.status,
        verification_status: f.verification_status,
        description: f.description,
        acceptance_criteria: (f.acceptance_criteria ?? []).map((c) => c.description),
      })),
    }, null, 2));
    return 0;
  }

  const { implemented_percent: impl, verified_percent: ver } = summary.progress;
  ctx.out(`${project.name}  (${config.apiUrl}/projects/${project.id})`);
  if (project.description) ctx.out(project.description);
  ctx.out('');
  ctx.out(summary.total === 0
    ? 'Progress: unknown (no features tracked yet)'
    : `Progress: ${impl}% implemented, ${ver}% implemented and verified (${summary.total} features)`);
  ctx.out(`Status: ${Object.entries(summary.by_status).filter(([, n]) => n).map(([s, n]) => `${n} ${label(s)}`).join(', ') || 'none'}`);
  ctx.out(`Verification: ${Object.entries(summary.by_verification_status).filter(([, n]) => n).map(([s, n]) => `${n} ${label(s)}`).join(', ') || 'none'}`);

  if (roadmap.phases.length) {
    const current = currentPhase(roadmap);
    ctx.out('');
    ctx.out('Roadmap:');
    roadmap.phases.forEach((p) => ctx.out(`${p === current ? '→' : ' '} ${phaseLine(p)}`));
  }

  if (dashboard.needs_attention.length) {
    ctx.out('');
    ctx.out(`Needs attention (${summary.needs_attention}):`);
    dashboard.needs_attention.forEach((f) => ctx.out(`  #${f.id} ${f.name}: ${label(f.status)}, ${label(f.verification_status)}`));
  }

  if (dashboard.unresolved.length) {
    ctx.out('');
    ctx.out(`Open unresolved work (${summary.open_unresolved}). Close items you fix with "resolves": [id] in a report:`);
    const tag = { client: '[waiting on the client] ', team: '[waiting on the team] ', agent: '' } as const;
    dashboard.unresolved.forEach((u) => ctx.out(`  #${u.id} ${u.waiting_on ? tag[u.waiting_on] : u.waiting_on === null ? '[not sorted] ' : ''}${u.decision_note && u.waiting_on !== 'agent' ? `${u.decision_note} — ` : ''}${u.text}${u.report_id ? ` (from report #${u.report_id})` : ''}`));
    const w = summary.unresolved_waiting_on;
    if (w?.client || w?.team) ctx.out(`  Don't work on items waiting on the client (${w.client}) or the team (${w.team}) until you're given the decision.`);
  }

  const mc = summary.mismatches;
  if (mc && (mc.open || mc.decided)) {
    ctx.out('');
    ctx.out(`PRD vs design: ${mc.decided} decided to apply, ${mc.open} waiting for a decision (${mc.team} yours, ${mc.client} the client's).`);
    (dashboard.mismatches ?? []).filter((m) => m.status === 'decided').forEach((m) => ctx.out(mismatchLine(m)));
    if (mc.open) ctx.out('  Don\'t pick a side on undecided ones; people decide them in Unseen.');
  }

  const open = dashboard.open_tickets ?? [];
  if (open.length) {
    const t = summary.tickets;
    ctx.out('');
    ctx.out(`Open tickets (${t?.open ?? open.length}${t?.sent_back ? `, ${t.sent_back} sent back by QA or the client` : ''}). See "unseen ticket show <ticket>":`);
    open.forEach((ticket) => ctx.out(ticketLine(ticket)));
  }

  ctx.out('');
  ctx.out(features.length ? 'Features:' : 'No features yet. Record them with "unseen api" (see the Unseen section in CLAUDE.md).');
  const byCategory = new Map<string, Feature[]>();
  for (const f of features) {
    const key = f.category?.name ?? 'Uncategorised';
    byCategory.set(key, [...(byCategory.get(key) ?? []), f]);
  }
  for (const [category, list] of [...byCategory].sort(([a], [b]) => a.localeCompare(b))) {
    ctx.out(`  ${category}`);
    list.sort((a, b) => a.name.localeCompare(b.name))
      .forEach((f) => ctx.out(`    #${f.id} ${f.name}: ${label(f.status)} / ${label(f.verification_status)}`));
  }
  return 0;
}
