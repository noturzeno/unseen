import { readFileSync } from 'node:fs';
import { resolve } from 'node:path';
import type { ApiClient, Feature, Ticket, TicketComment } from '../api.ts';
import { linkedProject, UsageError, type Context } from '../context.ts';
import { redactPersonalData, redactSecrets } from '../redact.ts';
import { PayloadError } from '../report-payload.ts';

export const TICKET_STATUSES = ['open', 'in_progress', 'ready_for_qa', 'qa_failed', 'client_review', 'changes_requested', 'done', 'closed'];
export const TICKET_TYPES = ['feature', 'bug', 'change_request', 'task'];
export const ROLES = ['qa', 'client', 'developer', 'project_manager', 'agent'];
const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

export interface TicketOptions {
  action: string;
  args: string[];
  title?: string;
  ref?: string;
  type?: string;
  priority?: string;
  feature?: string;
  raisedBy?: string;
  description?: string;
  comment?: string;
  status?: string;
  role?: string;
  author?: string;
  body?: string;
  file?: string;
  note?: string;
  all?: boolean;
  finished?: boolean;
  json?: boolean;
}

const USAGE = `Usage:
  unseen ticket list [--all | --finished] [--status <status>] [--json]
  unseen ticket show <ticket> [--json]
  unseen ticket add --title <text> [--ref <WEB-142>] [--type <type>] [--priority <p>] [--feature <id|name>]
                    [--raised-by <role>] [--author <name>] [--description <text>] [--comment <text>] [--status <status>]
  unseen ticket move <ticket> <status> [--note <text>] [--role <role>] [--author <name>]
  unseen ticket comment <ticket> --role <role> (--body <text> | --file <path|->) [--author <name>] [--status <status>]
  unseen ticket update <ticket> [--title] [--ref] [--type] [--priority] [--feature] [--description]
<ticket> is a reference such as WEB-142, or #id.`;

const label = (value: string) => value.replace(/_/g, ' ');

function oneOf(value: string | undefined, allowed: string[], flag: string): string | undefined {
  if (value === undefined) return undefined;
  if (!allowed.includes(value)) throw new UsageError(`${flag} must be one of: ${allowed.join(', ')}.`);
  return value;
}

/** "#12" or "12" is an id; anything else is the ticket's reference (case-insensitive). */
export async function findTicket(client: ApiClient, projectId: number, ref: string): Promise<Ticket> {
  const id = /^#?(\d+)$/.exec(ref.trim());
  if (id) return client.ticket(Number(id[1]));
  const matches = await client.tickets(projectId, `reference=${encodeURIComponent(ref.trim())}`);
  if (!matches.length) throw new PayloadError(`No ticket "${ref}" in this project. Run "unseen ticket list --all", or add it with "unseen ticket add".`);
  return matches[0]!;
}

function resolveFeature(ref: string, features: Feature[]): number {
  if (/^#?\d+$/.test(ref)) {
    const id = Number(ref.replace('#', ''));
    if (!features.some((f) => f.id === id)) throw new PayloadError(`No feature with id ${id} in this project.`);
    return id;
  }
  const matches = features.filter((f) => f.name.trim().toLowerCase() === ref.trim().toLowerCase());
  if (matches.length !== 1) {
    throw new PayloadError(matches.length
      ? `"${ref}" matches several features (ids ${matches.map((f) => f.id).join(', ')}). Use the id.`
      : `No feature named "${ref}". Run "unseen status" to list features.`);
  }
  return matches[0]!.id;
}

/**
 * Tickets and the QA / client / team comments on them. The coding agent records the ticket it is
 * working on, moves it through the flow (in progress → ready for QA → client review → done), and
 * records feedback people pass on, with the role of whoever said it. Secrets, emails, phone numbers
 * and ID numbers are masked before anything is sent.
 */
export async function ticket(opts: TicketOptions, ctx: Context): Promise<number> {
  const { config, client, token } = linkedProject(ctx);
  const pid = config.projectId;
  const [ref, ...rest] = opts.args;

  /** Masks secrets and personal contact details, and says where. */
  const clean = <T>(payload: T): T => {
    const secrets = redactSecrets(payload, [token]);
    const personal = redactPersonalData(secrets.value);
    if (secrets.redacted.length) ctx.err(`Masked what looked like secrets in: ${secrets.redacted.join(', ')}.`);
    if (personal.redacted.length) ctx.err(`Masked emails, phone or ID numbers in: ${personal.redacted.join(', ')}. Keep personal data out of Unseen.`);
    return personal.value;
  };

  const featureId = async () => (opts.feature === undefined ? undefined : resolveFeature(opts.feature, await client.features(pid)));

  const print = (t: Ticket, heading?: string) => {
    if (opts.json) {
      ctx.out(JSON.stringify({ ...t, url: `${config.apiUrl}/tickets/${t.id}` }, null, 2));
      return;
    }
    if (heading) ctx.out(heading);
    printTicket(t, ctx, config.apiUrl);
  };

  switch (opts.action) {
    case 'list': {
      if (opts.args.length) throw new UsageError(USAGE);
      const status = oneOf(opts.status, TICKET_STATUSES, '--status');
      const state = opts.all ? 'all' : opts.finished ? 'finished' : 'open';
      const list = await client.tickets(pid, status ? `status=${status}` : `state=${state}`);
      if (opts.json) {
        ctx.out(JSON.stringify(list, null, 2));
        return 0;
      }
      ctx.out(list.length ? `${list.length} ${status ? label(status) : state} tickets (${config.apiUrl}/projects/${pid}/tickets):` : `No ${status ? label(status) : state} tickets.`);
      list.forEach((t) => ctx.out(ticketLine(t)));
      return 0;
    }

    case 'show': {
      if (!ref || rest.length) throw new UsageError(USAGE);
      const found = await findTicket(client, pid, ref);
      print(found.comments ? found : await client.ticket(found.id));
      return 0;
    }

    case 'add': {
      if (opts.args.length || !opts.title) throw new UsageError(`unseen ticket add needs --title.\n${USAGE}`);
      const payload = clean({
        title: opts.title,
        reference: opts.ref,
        type: oneOf(opts.type, TICKET_TYPES, '--type'),
        priority: oneOf(opts.priority, PRIORITIES, '--priority'),
        raised_by: oneOf(opts.raisedBy, ROLES, '--raised-by'),
        author_name: opts.author,
        description: opts.description,
        comment: opts.comment,
        status: oneOf(opts.status, TICKET_STATUSES, '--status'),
        feature_id: await featureId(),
      });
      print(await client.createTicket(pid, payload), 'Ticket recorded.');
      return 0;
    }

    case 'move': {
      const [status] = rest;
      if (!ref || !status || rest.length > 1) throw new UsageError(`unseen ticket move <ticket> <status>. Statuses: ${TICKET_STATUSES.join(', ')}.`);
      oneOf(status, TICKET_STATUSES, 'Status');
      const payload = clean({ status, note: opts.note, author_role: oneOf(opts.role, ROLES, '--role'), author_name: opts.author });
      const found = await findTicket(client, pid, ref);
      print(await client.updateTicket(found.id, payload), `Moved to ${label(status)}.`);
      return 0;
    }

    case 'comment': {
      if (!ref || rest.length) throw new UsageError(USAGE);
      if (!opts.role) throw new UsageError(`Say whose words these are with --role (${ROLES.join(', ')}).`);
      if (opts.body !== undefined && opts.file !== undefined) throw new UsageError('Use --body or --file, not both.');
      const body = opts.file === '-' ? await ctx.readStdin()
        : opts.file ? readFileSync(resolve(ctx.cwd, opts.file), 'utf8')
          : opts.body;
      if (!body?.trim()) throw new UsageError('Give the comment with --body "<text>" or --file <path|->.');
      const payload = clean({
        author_role: oneOf(opts.role, ROLES, '--role'),
        author_name: opts.author,
        body: body.trim(),
        status: oneOf(opts.status, TICKET_STATUSES, '--status'),
      });
      const found = await findTicket(client, pid, ref);
      const saved = await client.commentOnTicket(found.id, payload);
      if (opts.json) {
        ctx.out(JSON.stringify({ ...saved, url: `${config.apiUrl}/tickets/${found.id}` }, null, 2));
        return 0;
      }
      ctx.out(`${roleLabel(saved.comment.author_role)} comment recorded on ${refOf(saved.ticket)}`
        + `${saved.comment.to_status ? `, now ${label(saved.comment.to_status)}` : ''}: ${config.apiUrl}/tickets/${found.id}`);
      return 0;
    }

    case 'update': {
      if (!ref || rest.length) throw new UsageError(USAGE);
      const payload = clean(Object.fromEntries(Object.entries({
        title: opts.title,
        reference: opts.ref,
        type: oneOf(opts.type, TICKET_TYPES, '--type'),
        priority: oneOf(opts.priority, PRIORITIES, '--priority'),
        raised_by: oneOf(opts.raisedBy, ROLES, '--raised-by'),
        description: opts.description,
        feature_id: await featureId(),
      }).filter(([, v]) => v !== undefined)));
      if (!Object.keys(payload).length) throw new UsageError('Nothing to update. To change the status use "unseen ticket move".');
      const found = await findTicket(client, pid, ref);
      print(await client.updateTicket(found.id, payload), 'Ticket updated.');
      return 0;
    }

    default:
      throw new UsageError(USAGE);
  }
}

const refOf = (t: Pick<Ticket, 'id' | 'reference'>) => t.reference ?? `#${t.id}`;
const roleLabel = (role: string) => ({ qa: 'QA', client: 'Client', developer: 'Developer', project_manager: 'Project manager', agent: 'Agent' })[role] ?? role;

export function ticketLine(t: Ticket): string {
  const parts = [label(t.status), label(t.type)];
  if (t.feature) parts.push(`feature #${t.feature.id} ${t.feature.name}`);
  let line = `  ${refOf(t)} ${t.title} [${parts.join(', ')}]`;
  if (t.latest_feedback?.body) line += `\n      ${roleLabel(t.latest_feedback.author_role)}: ${t.latest_feedback.body.split('\n')[0]!.slice(0, 140)}`;
  return line;
}

function printTicket(t: Ticket, ctx: Context, apiUrl: string) {
  ctx.out(`${refOf(t)} ${t.title}  (${apiUrl}/tickets/${t.id})`);
  const meta = [label(t.status), label(t.type)];
  if (t.priority) meta.push(`${t.priority} priority`);
  if (t.raised_by) meta.push(`raised by ${roleLabel(t.raised_by)}`);
  if (t.feature) meta.push(`feature #${t.feature.id} ${t.feature.name}`);
  ctx.out(meta.join(' · '));
  if (t.description) ctx.out(`\n${t.description}`);
  if (t.comments?.length) {
    ctx.out('\nThread:');
    t.comments.forEach((c: TicketComment) => {
      const who = `${roleLabel(c.author_role)}${c.author_name ? ` (${c.author_name})` : ''}`;
      const move = c.to_status ? (c.from_status ? ` moved ${label(c.from_status)} → ${label(c.to_status)}` : ` opened as ${label(c.to_status)}`) : '';
      ctx.out(`  ${c.created_at.slice(0, 16).replace('T', ' ')} ${who}${move}${c.report_id ? ` (report #${c.report_id})` : ''}`);
      if (c.body) c.body.split('\n').forEach((l) => ctx.out(`      ${l}`));
    });
  }
}
