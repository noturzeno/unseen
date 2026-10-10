import { parseArgs } from 'node:util';
import { ApiError, VERSION } from './api.ts';
import { api } from './commands/api.ts';
import { init } from './commands/init.ts';
import { mismatch } from './commands/mismatch.ts';
import { report } from './commands/report.ts';
import { roadmap } from './commands/roadmap.ts';
import { runner } from './commands/runner.ts';
import { scan } from './commands/scan.ts';
import { status } from './commands/status.ts';
import { ticket } from './commands/ticket.ts';
import { verify } from './commands/verify.ts';
import { ConfigError } from './config.ts';
import { UsageError, type Context } from './context.ts';
import { JunitError } from './junit.ts';
import { installLinkHandler, markRunnerRunning, openLink, runnerIsRunning } from './link.ts';
import { PayloadError } from './report-payload.ts';

const HELP = `unseen ${VERSION}: link a repository to Unseen and report coding-agent work

Usage:
  unseen init   [--api-url <url>] [--project <id>] [--force] [--token-stdin]
  unseen status [--json]
  unseen report [--file <path|->] [--summary <text>] [--agent <name>] [--no-git] [--dry-run] [--json]
  unseen scan   [--dry-run] [--json] [--no-git]
  unseen roadmap draft
  unseen roadmap show [--json]
  unseen roadmap sync --file <path|-> [--dry-run] [--json]
  unseen ticket  list [--all|--finished] [--status <s>] [--json] | show <ticket> [--json]
  unseen ticket  add --title <text> [--ref <REF>] [--type <t>] [--priority <p>] [--feature <id|name>]
                     [--raised-by <role>] [--author <name>] [--description <text>] [--comment <text>] [--status <s>]
  unseen ticket  move <ticket> <status> [--note <text>] [--role <role>] [--author <name>]
  unseen ticket  comment <ticket> --role <role> (--body <text> | --file <path|->) [--author <name>] [--status <s>]
  unseen ticket  update <ticket> [--title] [--ref] [--type] [--priority] [--feature] [--description]
  unseen mismatch list [--status open|decided|applied] [--json] | show <id> [--json]
  unseen mismatch add --file <path|-> [--dry-run] | applied <id> --note <text>
  unseen verify --junit <report.xml> [--junit <more.xml>] [--run "<test command>"] [--no-git] [--dry-run] [--json]
  unseen api    <GET|POST|PUT|PATCH|DELETE> <path> [--data '<json>' | --file <path|->]
  unseen runner [--project <id>] [--claude <command>] [--print] [--interval <seconds>] [--once]
  unseen open-link <unseen://runner link>   (used by the "Start runner" button)

Commands:
  init     Link this directory to an Unseen project (writes .unseen.json, no secrets).
           Signs in and stores an agent token (read, report, record) in ~/.config/unseen if needed.
           --token-stdin saves a token piped in instead (from the web app's "Connect a coding agent").
  status   Show progress, items needing attention, unresolved work and all features with ids.
           --json gives the same as machine-readable context for coding agents.
  report   Submit a structured report of work done. Takes JSON from --file, "-" or piped stdin.
           Features can be ids or names. Git branch and commit are added unless --no-git.
           Likely secrets are masked before sending. --dry-run prints the payload instead.
  scan     Look through the repository for likely features (routes, controllers, models, pages,
           tests, README) and send them as suggestions to review in Unseen. Only paths and
           names are sent, never file contents. --dry-run lists what was found.
  roadmap  Keep Unseen's roadmap identical to the project's plan: phases (key, name, status,
           target date) and their implementation lists. "sync" replaces the whole roadmap from JSON;
           items can be "[x] text" strings or {text, done, feature}. "show" prints it. "draft"
           prints roadmap JSON built from the repo's plan docs (Phase N sections) to check, then sync.
  ticket   Record the tickets you work on and their flow: open → in_progress → ready_for_qa
           (→ qa_failed) → client_review (→ changes_requested) → done, or closed. "comment" records
           feedback people pass on to you with whose words they are (--role qa, client, developer,
           project_manager, agent); add --status when the feedback moves the ticket (QA failed it,
           the client approved it). <ticket> is its reference (WEB-142) or #id. Types: feature, bug,
           change_request, task. Secrets, emails, phone and ID numbers are masked before sending.
  mismatch Record where the PRD and the design disagree (JSON with title, prd_says, design_says,
           built_to design|prd|neither|not_built, built_note, impact, decide_by team|client, feature,
           reference, key). A stable "key" updates instead of duplicating. People decide each one in
           Unseen; agents can't. "list --status decided" shows decisions to carry out; then mark each
           "applied" with a note.
  verify   Send automated test results (JUnit XML) so Unseen can mark features verified or
           failed based on their checks. --run runs the test command first and exits with its code.
           Needs a token with the "verify" ability (e.g. CI), not the agent token from init.
  runner   Keep running on your computer so "Proceed" buttons in Unseen open Claude Code with the
           prompt in the project's folder (set in the project's Settings). Watches every project this
           computer is linked to. Only opens folders linked to the same project. --print shows the
           command instead of opening a terminal. Ctrl+C stops it. The first time, it also sets up the
           "Start runner" button in Unseen (an unseen:// link handler) on this computer.
  api      Send one request to the linked Unseen API (/api/v1) with the stored token, e.g. to
           record features, statuses, criteria, checks and diagrams. Prints the JSON response.
           The token is never printed; likely secrets in the body are masked.

Environment:
  UNSEEN_TOKEN       API token (takes precedence over the credentials file)
  UNSEEN_API_URL     Default URL for "init"
  UNSEEN_CONFIG_DIR  Where credentials.json lives (default ~/.config/unseen)
`;

export async function main(argv: string[], ctx: Context): Promise<number> {
  const [command, ...rest] = argv;

  try {
    switch (command) {
      case 'init': {
        const { values } = parseArgs({ args: rest, options: {
          'api-url': { type: 'string' }, project: { type: 'string' }, force: { type: 'boolean' }, 'token-stdin': { type: 'boolean' },
        } });
        return await init({ apiUrl: values['api-url'], project: values.project, force: values.force, tokenStdin: values['token-stdin'] }, ctx);
      }
      case 'status': {
        const { values } = parseArgs({ args: rest, options: { json: { type: 'boolean' } } });
        return await status({ json: values.json }, ctx);
      }
      case 'report': {
        const { values } = parseArgs({ args: rest, options: {
          file: { type: 'string', short: 'f' }, summary: { type: 'string', short: 's' }, agent: { type: 'string' },
          'no-git': { type: 'boolean' }, 'dry-run': { type: 'boolean' }, json: { type: 'boolean' },
        } });
        return await report({
          file: values.file, summary: values.summary, agent: values.agent,
          git: !values['no-git'], dryRun: values['dry-run'], json: values.json,
        }, ctx);
      }
      case 'scan': {
        const { values } = parseArgs({ args: rest, options: {
          'dry-run': { type: 'boolean' }, json: { type: 'boolean' }, 'no-git': { type: 'boolean' },
        } });
        return await scan({ dryRun: values['dry-run'], json: values.json, git: !values['no-git'] }, ctx);
      }
      case 'roadmap': {
        const { values, positionals } = parseArgs({ args: rest, allowPositionals: true, options: {
          file: { type: 'string', short: 'f' }, 'dry-run': { type: 'boolean' }, json: { type: 'boolean' },
        } });
        return await roadmap({ action: positionals[0] ?? '', file: values.file, dryRun: values['dry-run'], json: values.json }, ctx);
      }
      case 'ticket': {
        const { values, positionals } = parseArgs({ args: rest, allowPositionals: true, options: {
          title: { type: 'string' }, ref: { type: 'string' }, type: { type: 'string' }, priority: { type: 'string' },
          feature: { type: 'string' }, 'raised-by': { type: 'string' }, description: { type: 'string' },
          comment: { type: 'string' }, status: { type: 'string' }, role: { type: 'string' }, author: { type: 'string' },
          body: { type: 'string' }, file: { type: 'string', short: 'f' }, note: { type: 'string' },
          all: { type: 'boolean' }, finished: { type: 'boolean' }, json: { type: 'boolean' },
        } });
        const [action = '', ...args] = positionals;
        return await ticket({ ...values, action, args, raisedBy: values['raised-by'] }, ctx);
      }
      case 'mismatch': {
        const { values, positionals } = parseArgs({ args: rest, allowPositionals: true, options: {
          file: { type: 'string', short: 'f' }, status: { type: 'string' }, note: { type: 'string' },
          json: { type: 'boolean' }, 'dry-run': { type: 'boolean' },
        } });
        const [action = '', ...args] = positionals;
        return await mismatch({ action, args, file: values.file, status: values.status, note: values.note, json: values.json, dryRun: values['dry-run'] }, ctx);
      }
      case 'verify': {
        const { values } = parseArgs({ args: rest, options: {
          junit: { type: 'string', multiple: true }, run: { type: 'string' },
          'dry-run': { type: 'boolean' }, json: { type: 'boolean' }, 'no-git': { type: 'boolean' },
        } });
        return await verify({
          junit: values.junit ?? [], run: values.run, dryRun: values['dry-run'], json: values.json, git: !values['no-git'],
        }, ctx);
      }
      case 'runner': {
        const { values } = parseArgs({ args: rest, options: {
          project: { type: 'string' }, claude: { type: 'string' }, print: { type: 'boolean' },
          interval: { type: 'string' }, once: { type: 'boolean' },
        } });
        const interval = values.interval === undefined ? undefined : Number(values.interval);
        if (interval !== undefined && !(interval >= 1)) throw new UsageError('--interval must be a number of seconds (1 or more).');
        if (!values.once) {
          if (runnerIsRunning(ctx.env)) ctx.err('Note: another Unseen runner is already running on this computer.');
          markRunnerRunning(ctx.env);
          // Lets "Start runner" buttons in Unseen start it next time (registers unseen:// links).
          const link = await installLinkHandler();
          if (!link.ok) ctx.err(`Note: ${link.error}`);
          else if (link.changed) ctx.out('Set up the "Start runner" button in Unseen for this computer.');
        }
        return await runner({ project: values.project, claude: values.claude, print: values.print, interval, once: values.once }, ctx);
      }
      case 'open-link': {
        const { positionals } = parseArgs({ args: rest, allowPositionals: true, options: {} });
        if (positionals.length !== 1) throw new UsageError('Usage: unseen open-link <unseen://runner link>');
        try {
          return await openLink(positionals[0]!, ctx);
        } catch (error) {
          if (error instanceof Error && /link|folder/i.test(error.message)) throw new UsageError(error.message);
          throw error;
        }
      }
      case 'api': {
        const { values, positionals } = parseArgs({ args: rest, allowPositionals: true, options: {
          data: { type: 'string', short: 'd' }, file: { type: 'string', short: 'f' },
        } });
        const [method, path] = positionals;
        if (positionals.length !== 2 || !method || !path) throw new UsageError('Usage: unseen api <METHOD> <path> [--data <json> | --file <path|->]');
        if (values.data !== undefined && values.file !== undefined) throw new UsageError('Use --data or --file, not both.');
        return await api({ method, path, data: values.data, file: values.file }, ctx);
      }
      case '--version':
      case '-v':
        ctx.out(VERSION);
        return 0;
      case undefined:
      case 'help':
      case '--help':
      case '-h':
        ctx.out(HELP);
        return 0;
      default:
        throw new UsageError(`Unknown command "${command}".`);
    }
  } catch (error) {
    if (error instanceof UsageError || (error instanceof TypeError && 'code' in error && String(error.code).startsWith('ERR_PARSE_ARGS'))) {
      ctx.err(`${error.message}\nRun "unseen --help" for usage.`);
      return 2;
    }
    if (error instanceof ApiError) {
      ctx.err(error.describe());
      return 1;
    }
    if (error instanceof ConfigError || error instanceof PayloadError || error instanceof JunitError) {
      ctx.err(error.message);
      return 1;
    }
    if (error instanceof Error && 'code' in error && error.code === 'ENOENT') {
      ctx.err(error.message);
      return 1;
    }
    throw error;
  }
}
