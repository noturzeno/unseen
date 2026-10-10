# Unseen

Unseen keeps track of what AI coding agents (such as Claude Code) have built in your projects. It holds a
plain-English **feature inventory** where **implementation** (is it built?) and **verification** (has it been
shown to work?) are tracked separately, and every claim records its source and evidence.

## Why

When agents write much of the code, it's easy to lose track of which features exist, which are finished, and which
actually work. Unseen answers those questions:

- **What features does this project have?** The feature inventory, recorded by coding agents (and from repository scans).
- **What did the agent do?** Structured development reports: features touched, files, tests, open work.
- **Does it actually work?** Verification from people, or from CI test runs checked against each feature's tests.
- **What still needs attention?** Failed checks, blocked features, unverified work, and unresolved items.
- **Where does the build differ from the PRD?** The design can differ from the PRD; each difference is shown side by
  side with what was built, for you (or the client) to decide.
- **Where is each ticket, and what did QA and the client say?** Tickets move through build → QA → client review →
  done, with QA and client comments recorded by the agent and shown on the dashboard.

## Core rules

1. **Code existing is not proof.** A route or model in the code only suggests a feature might exist.
2. **Agent-reported is not verified.** An agent saying "done" moves a feature to *needs verification*, never to *verified*.
3. **Only a trusted test run verifies.** Agent tokens cannot submit test results or mark features verified.
4. **Agents write, people watch and decide.** Coding agents record all tracking data through the API; the web app is
   view only, apart from projects, members, API tokens, and deciding PRD vs design mismatches.

## What's included

| Area | Highlights |
|---|---|
| **Web app** (view only) | Dashboard with charts and documented progress calculation · tickets with QA and client feedback · feature inventory with lifecycle, diagrams and timeline · reports · scan suggestions · verification results · manage projects, members and API tokens |
| **REST API** (`/api/v1`) | Where agents write everything: features, categories, criteria, statuses, checks, diagrams, reports, scans. Tokens are limited to read / report / write / verify |
| **CLI** (`unseen`) | `init` · `status` · `report` · `scan` · `roadmap` · `ticket` · `mismatch` · `verify` |
| **Repository scan** | Finds likely features from routes, controllers, models, pages and tests. Only paths and names leave the machine |
| **Verification** | Feature checks matched against JUnit results from PHPUnit, Jest, Vitest, pytest and `node --test` |

## Repository layout

| Path | What |
|---|---|
| [`api/`](api) | Laravel 13 app: REST API and Livewire web UI (PostgreSQL) |
| [`packages/cli/`](packages/cli) | `unseen` CLI (TypeScript, Node 22.18+, no runtime dependencies) |
| [`doc/`](doc) | Documentation: user guide, agent guide, product spec |

## Quick start (local)

```bash
# 1. Server
cd api
composer install
cp .env.example .env && php artisan key:generate
createdb unseen                 # PostgreSQL; or use SQLite for a quick trial (see user guide 1)
php artisan migrate
php artisan unseen:create-user you@example.com --name="Your Name"
php artisan serve                  # http://127.0.0.1:8000

# 2. CLI
cd ../packages/cli
npm install && npm run build && npm link

# 3. Link a repository
cd ~/code/your-project
unseen init
unseen status
```

Then add the [instruction snippet](doc/agent-guide/instructions-snippet.md) to the project's `CLAUDE.md`, so Claude Code
checks Unseen before working and reports afterwards.

## Documentation

| For | Start here |
|---|---|
| **People using Unseen** | [User guide](doc/README.md): 11 step-by-step guides, cheat sheet, glossary |
| **Coding agents** | [Agent guide](doc/agent-guide/README.md) · [report JSON Schema](doc/agent-guide/report.schema.json) · [instruction snippets](doc/agent-guide/instructions-snippet.md) |
| **Administrators** | [Administration guide](doc/user-guide/10-administration.md): users, production, backups, updates |
| **Developers** | [API and server reference](api/README.md) · [CLI reference](packages/cli/README.md) · [product spec](doc/overview.md) |

## Development

```bash
cd api && php artisan test          # server test suite (SQLite in memory)
vendor/bin/pint                     # PHP code style
cd ../packages/cli && npm test      # CLI test suite
npm run typecheck
```

The server tests also pass on PostgreSQL. Point `DB_CONNECTION=pgsql` and `DB_DATABASE` at a test database
to run them there.

## Status

All milestones in the [product spec](doc/overview.md) are implemented: project management, feature inventory,
dashboard, agent reports, the Claude Code CLI, repository scan and verification. Team features (members, token
management, closable unresolved work) are done too.

**Not included yet:** self sign-up and invitations (accounts are created by an administrator), password reset and
user deactivation screens (administrator commands are documented), and hosting-specific deployment configuration.

## Security and privacy

- Tokens are stored outside repositories and can expire. Agent tokens can't verify.
- Never put secrets or personal data in features, reports or test output. Hong Kong PDPO applies to any personal data stored.
- Report security problems (leaked tokens, exposed data) to **it@visibleone.com**.

See [Tokens and data safety](doc/user-guide/09-tokens-and-security.md).
# unseen
