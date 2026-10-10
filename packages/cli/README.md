# unseen CLI

Links a repository to an Unseen project so a developer or Claude Code can:

1. see the project's feature inventory and status (`unseen status`)
2. report the work that was done (`unseen report`)
3. find likely features already in the code (`unseen scan`)
4. send automated test results so features can be verified (`unseen verify`, usually in CI)

It needs Node 22.18 or newer and has no runtime dependencies.

## Install (from this monorepo)

```bash
cd packages/cli
npm install
npm run build
npm link            # puts `unseen` on your PATH
```

## Set up a repository

```bash
cd ~/code/my-project
unseen init                      # interactive: Unseen URL, sign in, pick a project
# or non-interactive (CI / scripted):
UNSEEN_TOKEN=... unseen init --api-url https://unseen.example.com --project 3
```

`init` writes `.unseen.json` with the URL and project id. It holds no secrets, so commit it
so everyone on the team uses the same project.

The easiest way to set up a repository is the web app's **Connect a coding agent** prompt: paste it into the
coding agent and it runs the install and `init` itself. The prompt pipes a project-scoped token in:

```bash
printf '%s' '<token>' | unseen init --api-url <url> --project <id> --token-stdin --force
```

`--token-stdin` checks the token against the server before saving it to the credentials file.

### Credentials

- `init` signs in once and creates a coding-agent token (`read`, `report`, `record`): it can read the project,
  record features through the API, and submit reports and scans. It cannot verify. It expires after 90 days; run
  `unseen init --force` to renew it. You can also create tokens on the **API tokens** page in Unseen.
- The token is stored in `~/.config/unseen/credentials.json` (mode `0600`), never in the repo.
  **Each project has its own token** (key `<url>#project=<id>`), so linking several projects on one computer
  never replaces another project's token. A token from signing in (key `<url>`) isn't tied to a project and is
  used for any linked project that has no token of its own.
  `UNSEEN_CONFIG_DIR` or `XDG_CONFIG_HOME` change that location.
- `UNSEEN_TOKEN`, if set, takes precedence over the file. Use it in CI.
- Plain `http://` is only accepted for local hosts (`localhost`, `127.0.0.1`, `*.test`, `*.localhost`).
  Everything else must use `https://`.
- To revoke a token, delete it from the credentials file and revoke it in Unseen
  (`DELETE /api/v1/auth/tokens/current`).

## Commands

### `unseen status [--json]`

Shows progress, features that need attention, unresolved work from recent reports, and every
feature with its id, implementation status and verification status. `--json` gives the same
information plus descriptions and acceptance criteria, for coding agents to read.

### `unseen report`

Submits a structured implementation report:

```bash
unseen report --file report.json        # from a file
cat report.json | unseen report         # from stdin
unseen report --summary "Fixed typo in footer"   # quick one-liner
unseen report --file report.json --dry-run       # show what would be sent
```

Example `report.json`:

```json
{
  "summary": "Added the password reset flow.",
  "agent": "claude-code",
  "confidence": "medium",
  "limitations": "Email delivery not tested against a real mail server.",
  "features": [
    { "name": "Password reset", "status": "implemented", "note": "Controller, views and mailer" },
    "Login"
  ],
  "changed_files": ["app/Http/Controllers/PasswordResetController.php"],
  "tests": [
    { "command": "php artisan test --filter=PasswordReset", "outcome": "passed" },
    { "command": "npm run e2e", "outcome": "not_run", "summary": "No browser available" }
  ],
  "unresolved": ["Rate limiting on reset requests"],
  "resolves": [31]
}
```

- **Required:** `summary` is the only required field.
- **Features** can be ids, exact names (case-insensitive), or objects with `id` or `name` plus an optional `status` and `note`.
  A name that doesn't match exactly one feature is an error. Run `unseen status` to see the names.
- **Test outcomes** are `passed`, `failed`, `error`, `skipped` or `not_run`.
- **Confidence** is `low`, `medium` or `high`.
- **`resolves`** closes open unresolved items: give the `#ids` shown by `unseen status`. This is recorded as the reporter's claim.
- **`unresolved`** entries can be strings or `{ "text", "waiting_on": "client" | "team" | "agent", "decision_note" }`,
  so the dashboard shows which open items wait on the client's or your decision. `unseen status` tags them.
- **`tickets`** moves tickets the work touched: `"WEB-142"`, `"#7"`, an id, or `{ "ref": "WEB-142", "status": "ready_for_qa", "note": "…" }`.
  Each ticket gets a thread entry linked to the report.
- **Git:** `git_branch` and `git_commit` are filled from the repository unless given or `--no-git` is used.
- **Unknown fields are rejected.** Reports cannot set verification; a person verifies features in Unseen.
- **Secrets:** before sending, the CLI masks what looks like a secret: API keys, tokens, private keys,
  `PASSWORD=...` pairs, credentials in URLs, and its own token. It tells you which fields it changed. This is a
  safety net, not a guarantee, so don't put secrets or personal data in reports.

### `unseen mismatch …`

Records where the PRD and the designer's design disagree, so people can decide each one on the project's
**PRD vs design** page. Agents record and carry out decisions; they can't decide.

```bash
unseen mismatch add --file /tmp/mismatches.json [--dry-run]   # one object or a list
unseen mismatch list [--status open|decided|applied] [--json]
unseen mismatch show 3
unseen mismatch applied 3 --note "Code now follows the PRD"
```

Fields: `title`, `prd_says`, `design_says` (required), `built_to` (`design`, `prd`, `neither`, `not_built`),
`built_note`, `impact`, `decide_by` (`team` or `client`), `feature` (name or id), `reference`, and `key` (same key
again = update, not a duplicate). Decision fields are rejected. Secrets, emails, phone and HKID numbers are masked.

### `unseen ticket …`

Records the tickets the agent works on, where each is in the flow, and the QA and client comments people pass on
to it. Everything shows on the project's **Tickets** tab and dashboard.

```bash
unseen ticket add --title "Pay button does nothing on Safari" --ref WEB-142 --type bug --priority high \
  --feature "Checkout" --raised-by client --comment "Tapping Pay on iPhone does nothing."
unseen ticket move WEB-142 in_progress
unseen ticket move WEB-142 ready_for_qa --note "Bound the handler to touchend too"
unseen ticket comment WEB-142 --role qa --body "Still fails on iOS 17" --status qa_failed
unseen ticket comment WEB-142 --role client --file /tmp/feedback.txt --status done
unseen ticket show WEB-142            # details and the whole thread (--json for agents)
unseen ticket list                    # open tickets; --finished, --all, --status <status>, --json
unseen ticket update WEB-142 --priority urgent --feature 12
```

- **Flow:** `open` → `in_progress` → `ready_for_qa` → `client_review` → `done`. QA can send it back with
  `qa_failed`, the client with `changes_requested`; `closed` means dropped.
- **`--role`** (comments, `move`) and **`--raised-by`** (add) say whose words they are: `qa`, `client`,
  `developer`, `project_manager` or `agent`. `--status` on a comment moves the ticket in the same entry.
- **`<ticket>`** is the reference (case-insensitive) or `#id`. **Types:** `feature`, `bug`, `change_request`, `task`.
  **Priorities:** `low`, `medium`, `high`, `urgent`.
- **Privacy:** secrets are masked as for reports, and so are emails, phone numbers and HKID numbers, so feedback
  carries what was said, not who to contact. Record roles, not people.
- Needs the `record` ability (the token from `init` has it). `unseen status` lists open tickets and the latest feedback.

### `unseen roadmap show | sync --file <path|-> [--dry-run] [--json]`

Keeps Unseen's roadmap identical to the project's own plan (ROADMAP.md, PLAN.md, milestones): phases and
their implementation lists. `sync` replaces the whole roadmap; `show` prints it (the current phase is marked `→`).
`unseen status` includes the roadmap too.

```json
{ "phases": [
  { "key": "MVP", "name": "MVP checkout", "description": "Buy without an account", "target_date": "2026-09-30",
    "items": ["[x] Product search", "[ ] Order confirmation email",
              { "text": "Card payments", "done": true, "feature": "Stripe payment" }] },
  { "key": "R2", "name": "Accounts", "status": "blocked", "items": ["[ ] Loyalty points"] }
] }
```

- Keep `key` stable: it is how a phase is matched between syncs. Phases left out are removed.
- Items: `"[x] text"` / `"[ ] text"` / `"text"` strings, or `{text, done, feature}` where `feature` is a feature
  id or name (resolved like in reports).
- `status` (`planned`, `in_progress`, `done`, `blocked`) is optional; without it, it's worked out from the items.
- Likely secrets are masked before sending; `--dry-run` prints the payload without sending.

### `unseen scan [--dry-run] [--json] [--no-git]`

Looks through the linked repository for signs of features and sends them to Unseen as
**suggestions** to review. Signs it looks for:

- routes (Laravel, Express-style, Next.js/Nuxt/SvelteKit pages)
- controllers and models (including Prisma)
- table migrations and Livewire components
- tests
- bullets under a "Features" heading in the README
- requirement tables in the project's Markdown docs (PRD, implementation matrix…): one suggestion per requirement
  id, with the section as category and the doc's status (🟢/🟡/🔴/⚪, "Done", "Partial"…) as the suggested status.
  Files named like `*.local.*`, `*secret*`, `*credential*`, `*password*` or `.env` are never read.

```bash
unseen scan --dry-run   # see what it finds; nothing is sent
unseen scan             # send; then review at <Unseen>/projects/<id>/suggestions
```

- Runs locally. Only file paths, line numbers, route paths and class names are sent, never file contents.
- Skips `vendor/`, `node_modules/`, build output, hidden folders and files over 512 KB, and doesn't follow symlinks.
  It stops after 20,000 files.
- Safe to run again: existing suggestions are updated and dismissed ones stay dismissed.
- Nothing becomes a feature until someone accepts it in Unseen. The CLI token can't do that.
- It's heuristic, so expect some noise. Dismiss what isn't a real feature, or merge it into one that is.

### `unseen verify --junit <report.xml> [--run "<test command>"]`

Sends automated test results to Unseen. Each feature has **checks** (set on its page in Unseen)
naming the tests that prove it. Unseen marks a feature **failed** if any of its checks fail, and
**verified** only when all of them pass, the feature is implemented, and the run is from a commit with
no uncommitted changes. The full policy is in the API README under "Verification".

```bash
# Run the tests and send the results in one step (exit code is the test command's)
unseen verify --run "php artisan test --log-junit junit.xml" --junit junit.xml
unseen verify --run "npx vitest run --reporter=junit --outputFile=junit.xml" --junit junit.xml
unseen verify --run "pytest --junitxml=junit.xml" --junit junit.xml
# Or send a report that already exists (repeat --junit for several files)
unseen verify --junit build/phpunit.xml --junit build/jest.xml
```

- **Needs a token with the `verify` ability.** The token `unseen init` creates for agents cannot
  verify; that is deliberate. Use a separate CI token (see below).
- **Uncommitted changes** (other than the report files themselves) are detected. Such runs can mark
  features failed but never verified.
- Failure messages are passed through secret masking and cut to 500 characters. Only test names,
  outcomes and those messages are sent.

#### In CI (GitHub Actions example)

Create a **CI test results** token on the API tokens page in Unseen and store it as the
`UNSEEN_TOKEN` secret. Commit `.unseen.json`. Then:

```yaml
- run: npx --prefix packages/cli unseen verify --run "php artisan test --log-junit junit.xml" --junit junit.xml
  env:
    UNSEEN_TOKEN: ${{ secrets.UNSEEN_TOKEN }}
```

Exit codes: `0` success, `1` error (API, config or input), `2` usage error.

### `unseen runner [--project <id>] [--claude <command>] [--print] [--interval <seconds>] [--once]`

Keeps running on your computer so **Proceed** buttons in Unseen open Claude Code with the prompt in the
project's folder. It only picks up Proceed clicks made by the person whose token it uses, and opens their own folder:
the one they set in the project's Settings or, if they haven't set one, the linked repository the runner was started in
(Unseen then saves it as their folder). Every member sets their own folder. It polls Unseen every few seconds for each project this
computer is linked to (`unseen init` / setup prompt), and only opens a folder whose `.unseen.json` points to
the same project and server. macOS: a new Terminal window; Linux: the first terminal program found; `--print`
prints the command instead. The prompt goes through a private temporary file that is deleted when Claude starts.

**Start runner button.** The first time `unseen runner` runs on a computer, it registers a `unseen://` link
handler (macOS: `~/Applications/Unseen Runner.app`; Linux: a `.desktop` entry via `xdg-mime`). After that, the
**Start runner** button in Unseen opens a terminal window running the runner, in your project folder if that folder
is linked to Unseen. The link can only start the runner (`unseen open-link unseen://runner?folder=…`), and it
does nothing while a runner is already running. Windows isn't supported yet: start the runner in a terminal.

### `unseen api <METHOD> <path> [--data '<json>' | --file <path|->]`

Sends one request to the linked Unseen's `/api/v1` with the stored token and prints the JSON response. This is
how coding agents record features, categories, acceptance criteria, statuses, checks and diagrams, and review scan
suggestions ([agent guide, section 3a](../../doc/agent-guide/README.md#3a-recording-features-with-unseen-api)).

```bash
unseen api GET  /projects/3/features
unseen api POST /projects/3/features --data '{"name":"Invoice download","status":"in_progress"}'
unseen api PATCH /features/12 --data '{"status":"implemented"}'
unseen api POST /features/12/diagrams --file /tmp/diagram.json
```

- The token is never printed, and paths are always relative to `/api/v1` on the linked server.
- Likely secrets in the body are masked before sending, as with `report`.
- Token management (`/auth/...`) is refused: people manage tokens in the web app.
- `verified` / `failed` are refused by the server: only `unseen verify` runs can verify.

## Using it with Claude Code

Full guide for agents (workflow, report format, error handling):
[`doc/agent-guide/README.md`](../../doc/agent-guide/README.md). The report format is also available as a JSON Schema in
[`doc/agent-guide/report.schema.json`](../../doc/agent-guide/report.schema.json).

Add this to the project's `CLAUDE.md`:

```markdown
## Unseen

This repository is tracked in Unseen (`.unseen.json`).

- Before starting a task, run `unseen status --json` to see the existing features, their
  status, and open unresolved work (with ids). Prefer existing feature names over inventing new ones.
- After finishing a task, write a report JSON (see `unseen report --help`) and submit it with
  `unseen report --file <file>`. Include every feature you changed, the files changed, the
  exact test commands you ran with their real outcomes (`not_run` if you did not run them),
  anything left unresolved, and honest limitations.
- Only claim `implemented` when the behaviour is actually built. Never claim verification; that
  comes from CI test runs (`unseen verify`), which this token cannot submit.
- Never put secrets, `.env` values or personal data in a report.
- If your work fully resolves an open unresolved item, list its id in `resolves`.
- If a feature you worked on is not in Unseen, say so in `unresolved` instead of guessing a name.
- After adding new routes, models or pages, run `unseen scan` so they show up for review.
```

## Development

The setup prompt installs the CLI from the Unseen server. After changing the CLI, rebuild that download:
`npm run pack:server` (writes `api/public/downloads/unseen-cli.tgz`).


```bash
npm test          # node --test, runs the TypeScript sources directly
npm run typecheck
npm run build     # compiles to dist/
```
