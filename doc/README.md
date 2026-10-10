# Unseen documentation

Unseen keeps track of what AI coding agents (such as Claude Code) have built in your projects. It holds
an inventory of features, tracking **implementation** (is it built?) separately from **verification**
(has it been shown to work?), and records the evidence behind every claim.

## User guide

Follow the guides in order the first time. After that, use them as reference.

| # | Guide | You will learn to… |
|---|---|---|
| 1 | [Getting started](user-guide/01-getting-started.md) | Install Unseen, create your account, sign in |
| 2 | [Projects and team](user-guide/02-projects-and-team.md) | Create projects, add colleagues, archive projects |
| 3 | [Features and categories](user-guide/03-features.md) | Build the feature inventory, use statuses correctly |
| 4 | [Reading the dashboard](user-guide/04-dashboard.md) | Understand progress, "needs attention", unresolved work |
| 5 | [Connecting Claude Code (CLI)](user-guide/05-claude-code-cli.md) | Install the `unseen` CLI and link a repository |
| 6 | [Development reports](user-guide/06-development-reports.md) | What an agent did, sent by the CLI |
| 7 | [Repository scan](user-guide/07-repository-scan.md) | Find features already in the code and review them |
| 8 | [Verification with automated tests](user-guide/08-verification.md) | Link tests to features and verify them from CI |
| 9 | [API tokens and data safety](user-guide/09-tokens-and-security.md) | Create the right tokens, keep secrets and personal data out |
| 10 | [Administration](user-guide/10-administration.md) | Manage users, run in production, back up, update |
| 11 | [Troubleshooting and FAQ](user-guide/11-troubleshooting.md) | Fix common problems |
| 12 | [Tickets, QA and client feedback](user-guide/12-tickets-and-feedback.md) | Follow tickets from request to sign-off, with QA and client comments |
| 13 | [PRD vs design](user-guide/13-prd-vs-design.md) | See where the build follows the design instead of the PRD, and decide each difference |

## Guide for coding agents

For Claude Code and other AI coding agents working in a tracked repository:

- [Agent guide](agent-guide/README.md): rules, workflow, report format, honest status choices, error handling, examples
- [Report JSON Schema](agent-guide/report.schema.json): machine-readable definition of a report
- [Instruction snippets](agent-guide/instructions-snippet.md): ready-to-paste text for `CLAUDE.md` and `AGENTS.md`

## Quick reference

- [Day-to-day cheat sheet](user-guide/cheat-sheet.md): the commands and clicks you'll use most
- [Glossary](user-guide/glossary.md): what each status and term means

## Technical reference

- [Product specification](overview.md): goals, rules and milestones
- [API and server reference](../api/README.md): endpoints, policies, progress calculation
- [CLI reference](../packages/cli/README.md): every `unseen` command and option

## The three rules to remember

1. **Code existing is not proof.** A route or model existing in the code only suggests a feature might be there.
2. **Agent-reported is not verified.** An agent saying "done" moves a feature to *needs verification*, never to *verified*.
3. **Only a trusted test run verifies.** Verification comes from CI test results checked against the feature's checks.
4. **Agents write, people watch.** Coding agents record the data; the web app shows it.
