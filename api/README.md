# Unseen API

Laravel 13 REST API for Unseen (see [`../doc/overview.md`](../doc/overview.md) for the product spec).
Authentication uses Laravel Sanctum personal access tokens.

## Setup

```bash
cp .env.example .env          # defaults to PostgreSQL database "unseen"
composer install
php artisan key:generate
createdb unseen            # Postgres must be running
php artisan migrate
php artisan unseen:create-user you@example.com --name="Your Name"   # prompts for password
php artisan serve             # web UI at http://127.0.0.1:8000 (sign in with the user created above)
```

There is no public sign-up in the private MVP; users are created with `unseen:create-user`.

## Tests

```bash
php artisan test              # SQLite in-memory (phpunit.xml)
DB_CONNECTION=pgsql DB_DATABASE=unseen_test php artisan test   # against Postgres
```

## API (v1)

All endpoints except `POST /auth/tokens` require `Authorization: Bearer <token>`.

| Method | Path | Notes |
|---|---|---|
| POST | `/api/v1/auth/tokens` | `{email, password, token_name, abilities?, expires_in_days?}` → `{token, abilities, expires_at}`. Throttled 6/min. |
| DELETE | `/api/v1/auth/tokens/current` | Revoke the token used for this request |
| GET | `/api/v1/auth/tokens` | Your tokens (no values), with last use and expiry. Needs `write` |
| DELETE | `/api/v1/auth/tokens/{id}` | Revoke one of your tokens. Needs `write` |
| GET | `/api/v1/me` | Current user |
| GET | `/api/v1/projects` | Paginated, each with a progress `summary` and `last_activity_at`. `?archived=exclude` (default) \| `include` \| `only`, `?per_page=` (max 100) |
| POST | `/api/v1/projects` | `{name, description?, repository_url?, tech_stack?}` |
| GET | `/api/v1/projects/{id}` | |
| PATCH | `/api/v1/projects/{id}` | Partial update of the same fields |
| POST | `/api/v1/projects/{id}/archive` | Owner only |
| GET | `/api/v1/projects/{id}/members` | Owner and members |
| POST | `/api/v1/projects/{id}/members` | `{email}`: an existing user is added (201). Anyone else is invited (202, `{invitation: {email, url, expires_at}}`; the link is emailed and returned once). Owner only |
| DELETE | `/api/v1/projects/{id}/members/{userId}` | Owner removes anyone; a member can remove themselves (leave) |
| DELETE | `/api/v1/projects/{id}/archive` | Unarchive, owner only |
| GET | `/api/v1/projects/{id}/dashboard` | Summary counts, progress, needs attention, recently updated features, recent status changes, recent reports, unresolved work |
| GET | `/api/v1/projects/{id}/categories` | With `features_count` |
| POST | `/api/v1/projects/{id}/categories` | `{name, description?, position?}`; name unique per project |
| PATCH / DELETE | `/api/v1/categories/{id}` | Delete leaves features uncategorised |
| GET | `/api/v1/projects/{id}/features` | Filters: `status`, `verification_status`, `category_id` (id or `none`) |
| POST | `/api/v1/projects/{id}/features` | `{name, description?, category_id?, status?, verification_status?, status_note?, acceptance_criteria?: string[]}` |
| GET / PATCH | `/api/v1/features/{id}` | PATCH is partial; `acceptance_criteria` replaces the whole list when sent |
| GET | `/api/v1/features/{id}/history` | Status change audit trail, newest first |
| GET | `/api/v1/features/{id}/evidence` | Reports that mention this feature |
| GET | `/api/v1/projects/{id}/reports` | Newest first. Filter: `feature_id` |
| POST | `/api/v1/projects/{id}/reports` | Submit a development report (see below) |
| GET | `/api/v1/reports/{id}` | One report. Reports cannot be edited or deleted |
| GET | `/api/v1/projects/{id}/unresolved` | `?status=open` (default) or `resolved` |
| GET | `/api/v1/projects/{id}/roadmap` | Phases in order, each with `progress` (items done, linked features implemented and verified) and its implementation list |
| POST | `/api/v1/projects/{id}/runs/claim` | `unseen runner`: `{runner, folder?}` → the token owner's next "Proceed" request (`{id, title, prompt, local_path, …}`) or 204. A runner only gets requests from the person whose token it uses, and `local_path` is that person's own folder. Also records their runner check-in, and `folder` (the repo the runner started in) becomes their folder if they haven't set one. Requests expire after 10 minutes (`record` or `write`) |
| PATCH | `/api/v1/runs/{id}` | `{status: launched\|failed, error?}` for a claimed request |
| PUT | `/api/v1/projects/{id}/roadmap` | Replace the whole roadmap (`record` or `write`): `{phases: [{key, name, description?, status?, target_date?: Y-m-d, items?: [{text, done?, feature_id?}]}]}`. Phases match by `key` (case-insensitive) and keep their row; phases not sent are removed; each list is replaced. Without `status` a phase is `planned` (nothing done), `done` (all done) or `in_progress`. Up to 50 phases × 200 items. Recorded as `agent_reported` |
| POST | `/api/v1/unresolved/{id}/resolve` / `reopen` | `{note?}`. Close or reopen an unresolved item |
| PATCH | `/api/v1/unresolved/{id}` | `{waiting_on: client\|team\|agent\|null, decision_note?}` (`record` or `write`): who the item waits on and what is needed. `GET /projects/{id}/unresolved` filters by `waiting_on` (or `unsorted`) |
| GET | `/api/v1/projects/{id}/mismatches` | PRD vs design mismatches, decided first. Filters: `status` (`open`, `decided`, `applied`), `feature_id`. `meta.counts` has `open`, `team`, `client`, `decided`, `applied` |
| GET | `/api/v1/mismatches/{id}` | One mismatch |
| POST | `/api/v1/projects/{id}/mismatches` | `{title, prd_says, design_says, built_to?, built_note?, impact?, decide_by?: team\|client, feature_id?, reference?, key?}` (`record` or `write`). Same `key` updates (200) instead of adding (201); never touches a decision |
| PATCH | `/api/v1/mismatches/{id}` | Edit the details (`record` or `write`) |
| POST | `/api/v1/mismatches/{id}/decide` | `{decision: follow_prd\|follow_design\|other, decision_note?}` (**`write` only**: people decide, agents can't). `other` needs a note. Not after it was applied |
| POST | `/api/v1/mismatches/{id}/applied` | `{note?}` (`record` or `write`): the decision was carried out. Refused while undecided |
| GET | `/api/v1/projects/{id}/tickets` | Tickets with `comments_count` and `latest_feedback` (newest QA or client comment). Filters: `state` (`open`, `finished` = done or closed, `all`), `status`, `type`, `feature_id`, `reference` (case-insensitive) |
| POST | `/api/v1/projects/{id}/tickets` | `{title, reference?, description?, type?, status?, priority?, raised_by?, feature_id?, comment?, author_name?}` (`record` or `write`). `reference` is unique per project. `comment` is the opening words of whoever raised it |
| GET | `/api/v1/tickets/{id}` | The ticket and its whole thread, oldest first |
| PATCH | `/api/v1/tickets/{id}` | Details, and/or `status` with `note`, `author_role`, `author_name` (`record` or `write`). A status change adds a thread entry |
| POST | `/api/v1/tickets/{id}/comments` | `{author_role, body, author_name?, status?}` (`record` or `write`): records QA, client or team feedback; with `status` it also moves the ticket |
| POST | `/api/v1/projects/{id}/scans` | Submit scan candidates (see "Repository scan") |
| GET | `/api/v1/projects/{id}/scans` | Scan history |
| GET | `/api/v1/projects/{id}/suggestions` | `?status=pending` (default), `accepted`, `merged` or `dismissed` |
| GET | `/api/v1/suggestions/{id}` | One suggestion with its evidence |
| POST | `/api/v1/suggestions/{id}/accept` | `{name?, description?, category_id?, status?}` → creates the feature (201) |
| POST | `/api/v1/suggestions/{id}/merge` | `{feature_id}` → links the evidence to an existing feature |
| POST | `/api/v1/suggestions/{id}/dismiss` / `restore` | Dismiss, or put a dismissed one back for review |
| GET / POST | `/api/v1/features/{id}/checks` | List (with latest result) / add `{pattern, description?}` |
| DELETE | `/api/v1/checks/{id}` | Remove a check |
| GET / POST | `/api/v1/features/{id}/diagrams` | List / add `{type, title, definition}`. `type` is `flowchart`, `use_case` or `sequence`; `definition` is Mermaid source (max 20,000 chars). Needs `report`, `record` or `write` |
| PUT / PATCH | `/api/v1/diagrams/{id}` | Update any of `type`, `title`, `definition` (`report`, `record` or `write`) |
| DELETE | `/api/v1/diagrams/{id}` | Remove a diagram (`record` or `write`) |
| POST | `/api/v1/projects/{id}/test-runs` | Submit test results (needs `verify`; see "Verification") |
| GET | `/api/v1/projects/{id}/test-runs`, `/api/v1/test-runs/{id}` | Test run history and detail with per-check results |

`repository_url` accepts `http(s)://`, `ssh://`, `git://`, `git@host:org/repo.git`, or an absolute local path.

### Tokens

Create and revoke tokens in the web UI at **API tokens** (`/settings/tokens`). The page offers three types:

| Type | Abilities | For |
|---|---|---|
| Coding agent (CLI) | `read`, `report` | `unseen init`, Claude Code |
| CI test results | `read`, `verify` | `unseen verify` in CI |
| Full access | all | Scripts acting fully as you |

Tokens can expire after 1 to 365 days. The page suggests 90, and `unseen init` always uses 90. A new
token's value is shown once. Expired tokens are deleted daily by the scheduler
(`sanctum:prune-expired --hours=24`), so run `php artisan schedule:work` (or a cron calling
`schedule:run`) in production.

### Token abilities

A token can be limited to certain kinds of action. Without `abilities` it gets all five.

| Ability | Allows |
|---|---|
| `read` | All GET endpoints |
| `report` | `POST /projects/{id}/reports`, `POST /projects/{id}/scans`, and adding or updating feature diagrams |
| `record` | Tracking data: creating and editing categories and features (not verification), acceptance criteria, statuses, checks and diagrams; reviewing suggestions; closing or reopening unresolved items. The coding-agent token |
| `write` | Everything `record` allows, plus creating and editing projects, archiving, members, and listing or revoking tokens. For people's own scripts, not agents |
| `verify` | `POST /projects/{id}/test-runs`, which can mark features verified or failed |

**Project-scoped tokens.** A token with a `project:<id>` ability (what **Connect a coding agent** issues, together
with `read`, `report`, `record` and a 90-day expiry) can only see and change that one project: other projects return
403, `GET /projects` lists only that one, and it can't create projects. The scope is enforced in `ProjectPolicy::before`
and `Project::scopeAccessibleBy`.

The CLI's `init` asks for `read` + `report` + `record`, so an agent's token can record tracking data but
**cannot verify its own work**, and cannot change projects, members or tokens. Give CI a separate `read` + `verify` token (the "CI test results" type on the API tokens page, or):

```bash
curl -X POST https://onez-unseen.vercel.app/api/v1/auth/tokens -H "Accept: application/json" \
  -H "Content-Type: application/json" \
  -d '{"email":"you@example.com","password":"…","token_name":"ci","abilities":["read","verify"]}'
``` Abilities limit what a token can do, while project access rules (below) still
decide which projects it can see.

### Authorization

- A user sees only projects they **own** or are a **member** of (`project_members`).
- Owners: view, edit, archive/unarchive, add and remove members, and invite contributors who have no account (`/invitations/{token}`: a one-time link, valid 7 days, only its hash stored; it creates the account with the invited email).
- Members: view and edit; cannot archive or manage members; can leave. Each contributor connects their own coding agent (their own project-limited token), so their features, reports and test runs land in the same project under their name.
- Projects are archived, never deleted, through the API.

### Feature statuses

Implementation status (`planned`, `in_progress`, `implemented`, `blocked`, `not_implemented`, `ignored`) and
verification status (`not_checked`, `needs_verification`, `verified`, `failed`) are separate fields.
All changes go through `App\Services\FeatureStatusService`, which records each change in
`feature_status_history` with its source (`user_entered`, `agent_reported`, `repository_detected`, `test_verified`). The rules are:

1. `verified` is only allowed while status is `implemented`.
2. Changing the status of a `verified` feature resets verification to `needs_verification`.
3. A feature that becomes `implemented` while `not_checked` moves to `needs_verification`.

Rules 2 and 3 apply only when the request doesn't set `verification_status` itself.
4. Through the feature endpoints, `verification_status` may only be `not_checked` or `needs_verification`.
   `verified` and `failed` come only from trusted test runs (`POST /projects/{id}/test-runs`, `verify` ability);
   anything else is rejected with 422.

Changes made through the API are recorded as `agent_reported`: the API is how coding agents write data.
Features can't be deleted, so their history is always kept.

### Agent reports

A development report records what a coding agent (or a person) says it did. Reports sent to the API
are stored as `agent_reported`. Reports entered in the web form are stored as `user_entered`.
Reports cannot be changed after they are saved.

```bash
export UNSEEN_TOKEN=...   # from POST /api/v1/auth/tokens; keep it out of git
curl -X POST http://127.0.0.1:8000/api/v1/projects/1/reports \
  -H "Authorization: Bearer $UNSEEN_TOKEN" -H "Accept: application/json" -H "Content-Type: application/json" \
  -d '{
    "summary": "Added password reset flow.",
    "agent": "claude-code",
    "confidence": "medium",
    "limitations": "Email delivery not tested against a real mail server.",
    "git_branch": "feature/password-reset",
    "git_commit": "a1b2c3d",
    "features": [{"id": 12, "status": "implemented", "note": "Controller and views added"}],
    "changed_files": [{"path": "app/Http/Controllers/PasswordResetController.php", "change": "added"}],
    "tests": [{"command": "php artisan test --filter=PasswordReset", "outcome": "passed"}],
    "unresolved": ["Rate limiting on reset requests"],
    "resolves": [31]
  }'
```

Only `summary` is required. Field limits:

- `features[].id` must belong to the project. `features[].status` is an implementation status.
- `changed_files[].change` is `added`, `modified`, `deleted` or `renamed`.
- `tests[].outcome` is `passed`, `failed`, `error`, `skipped` or `not_run`.
- `confidence` is `low`, `medium` or `high`. `git_commit` is a hex SHA of 7 to 64 characters.
- `reported_at` is optional. It defaults to now and cannot be in the future.
- `resolves` lists ids of open unresolved items in this project that the work closed.
- Unknown keys inside list items are dropped.

What a report does to the features it lists (`App\Services\DevelopmentReportService`):

1. **It never marks anything `verified`.** There is no verification field. Tests listed in a report are the reporter's claim and are shown as "reported".
2. A claimed status is applied with the report's source, so the normal status rules still apply. For example, `implemented` moves the feature to `needs_verification`.
3. A feature that was `verified` or `failed` moves back to `needs_verification`, because the code changed after it was checked.
4. Each listed feature gets a `feature_evidence` row linking it to the report.

An `unresolved` entry is a string or `{text, waiting_on?, decision_note?}`. `waiting_on` says who has to act: `client`
(the client must decide or provide something), `team` (the project's people must), or `agent` (no decision needed);
without it the item is "not sorted yet". The Unresolved work page and the dashboard group open items by it (waiting
on you first, then the client), agent prompts skip items waiting on a decision, and a "sort" prompt (run kind `sort`)
asks the agent to classify and split existing items. `dashboard.summary.unresolved_waiting_on` has the counts.

Each `unresolved` entry becomes an **unresolved item** that stays open until it is closed: by a person
(web UI or `POST /unresolved/{id}/resolve`, recorded as `user_entered`), or by a later report's `resolves`
(recorded with the report's source, e.g. `agent_reported`, and linked to that report). Items can be reopened.
The dashboard shows all open items.

Report text is stored exactly as submitted. Agents must not include secrets, `.env` contents or personal data in summaries, notes or test output.
The CLI masks likely secrets before sending, but the server does not check.

### PRD vs design mismatches

The build often follows the designer's design, which can differ from the PRD. A **mismatch** records one difference:
what the PRD says, what the design says, which one was built (`built_to`), the impact of changing it, and who should
decide (`team` or `client`). Rules (`App\Services\SpecMismatchService`):

1. The coding agent records them (`unseen mismatch add`). A `key` makes re-recording update the same row.
2. **Only people decide**: on the PRD vs design page (`/projects/{id}/mismatches`), or with a `write` token. This is
   the one kind of tracking data the web UI writes. Decisions are *Follow the PRD*, *Keep the design*, or
   *Something else* (with a note), and record who decided and when. A decision can be changed until it is applied.
3. The agent carries out decided ones (Follow the PRD: change the code; Keep the design: update the PRD) and marks
   them `applied`. An undecided mismatch can't be applied.

The overview shows a PRD vs design card near the top (yours to decide, the client's, decided and waiting for the
agent), feature pages list their mismatches, and decided ones become a high-priority next step for the agent
(run kind `apply_mismatches`; `find_mismatches` asks the agent to look for them). The dashboard API has
`summary.mismatches` counts and `mismatches` (decided first).

### Tickets and QA / client feedback

A **ticket** (`feature`, `bug`, `change_request`, `task`) is a piece of client work and where it is in the agency flow:

```
open → in_progress → ready_for_qa → client_review → done      (closed = dropped)
                          └→ qa_failed      └→ changes_requested   (sent back to development)
```

Its **thread** (`ticket_comments`) holds every comment and status change. Each entry has an `author_role`
(`qa`, `client`, `developer`, `project_manager`, `agent`): whose words they are. `user_id` and `source` say who
recorded it. Coding agents record tickets and the feedback people pass on to them (`unseen ticket`), so it is all
`agent_reported`. Rules (`App\Services\TicketService`):

1. Every status change becomes a thread entry, so the flow can be followed afterwards. `closed_at` is set on `done`
   or `closed` and cleared when a ticket is reopened.
2. A report's `tickets` (`[{id, status?, note?}]`) adds a thread entry linked to the report for each ticket.
3. A ticket's `done` is a claim about sign-off, not verification. It never changes a feature's verification.
4. Text is stored as sent. The CLI masks secrets, emails, phone and HKID numbers before sending. The server does not
   check, so agents are told to record roles, not people.

The dashboard (`summary.tickets`: `total`, `open`, `sent_back`, `by_status`; plus `open_tickets` and
`recent_feedback`, the latest QA and client comments) and the web UI show them. Open tickets are listed with
sent-back ones first.

### Repository scan

`unseen scan` (in the CLI) looks through the repository **on the developer's machine** and sends
only evidence: file paths, line numbers, route paths and class names. File contents never reach
the server, and the server never needs access to the repository. It looks for routes (Laravel,
Express-style, Next.js/Nuxt/SvelteKit pages), controllers, models (including Prisma), table
migrations, Livewire components, tests, bullets under a "Features" heading in the README, and
**requirement tables in Markdown docs** (PRDs, implementation matrices): one candidate per requirement id,
with `category` (the section) and `status` (the doc's status mapped to `implemented`, `in_progress`,
`blocked`, `planned` or `not_implemented`; never verification). The server stores these as
`suggested_category` / `suggested_status`, and accepting uses them as defaults (the category is found or
created by name). For these candidates the requirement wording is sent (trimmed, secrets masked).
Evidence is grouped by resource. For example `InvoiceController`, `app/Models/Invoice.php`,
`GET /invoices` and `InvoiceTest` all become one "Invoice" suggestion.

Rules (`App\Services\RepositoryScanService` and `SuggestionReviewService`):

1. **A scan never creates or changes features.** It only creates or updates suggestions, and code existing
   is shown as a sign a feature might be there, not proof that it works.
2. **Rescans don't duplicate.** Suggestions are unique per project and normalised key (`Invoices`,
   `invoice` and `InvoiceController` share the key `invoice`). A rescan refreshes the evidence.
3. **Decisions stick.** Dismissed suggestions stay dismissed. Accepted and merged ones stay linked
   to their feature, and their name and description are not overwritten.
4. **Possible duplicates are flagged, not merged.** When a suggestion's key matches an existing feature's name,
   the suggestion is flagged so the reviewer can merge it.
5. **Review needs the `write` ability**, so the CLI's token can scan but cannot accept anything.
   - **Accept** creates the feature with the chosen status (default `implemented`, which starts as `needs_verification`), recorded as `agent_reported`.
   - **Merge** attaches the evidence to an existing feature without changing its status.
   - **Dismiss** and **restore** are reversible.

### Verification

A feature is verified automatically only through **checks**. A check is a pattern, set by a person
on the feature page, that names the tests proving the feature works:

- `Tests\Feature\InvoiceTest` matches every test in that class.
- `*InvoiceTest::test_export*` uses `*` as a wildcard over `Class::method`.
- `tests/invoice.test.ts` matches by test file path.

Test results arrive as a **test run** (`unseen verify` sends JUnit XML from PHPUnit, Jest, Vitest,
pytest or `node --test`). `App\Services\VerificationService` applies this policy to every feature
that has checks:

| Outcome | When | Effect |
|---|---|---|
| `failed` | Any check has a failing or erroring test | Verification → `failed` |
| `verified` | Every check has ≥1 passing test and none failing, **and** the feature is `implemented`, **and** the run has a git commit with no uncommitted changes | Verification → `verified` |
| `passed_not_applied` | Checks all passed but one of those conditions is missing | No change (reason recorded) |
| `incomplete` | No failures, but some check had no tests run | No change |
| `not_covered` | None of the feature's checks ran (e.g. a partial test run) | No change |

- Skipped tests don't count as run.
- Changes are recorded with source `test_verified`, plus the run number and commit.
- A later agent report touching the feature resets it to `needs_verification`, as before.
- **Features without checks are never verified automatically.** The dashboard lists implemented features
  with no automated checks, so missing coverage is visible. Only test runs verify, so they stay unverified until
  an agent links tests to them.
- **Only tokens with `verify` can submit runs**, so results from the agent's own token are rejected. Test
  outcomes inside development reports stay "reported" and never verify anything.

Only totals, per-check results and up to 5 failure messages per check (500 characters each) are stored,
not every test case.

### Progress calculation

Implemented in `App\Services\ProjectDashboard::progress()` and used by both the API and the web UI:

```
implemented_percent = floor(implemented features / all tracked features × 100)
verified_percent    = floor(features that are implemented AND verified / all tracked features × 100)
```

- **Every tracked feature counts** in the total, whatever its status (including `not_implemented` and `blocked`),
  except `ignored` ones: a person decided they won't be built. Only people set or lift `ignored` (on the
  feature page); the API refuses it from agents, and reports don't change an ignored feature's status.
- Percentages are **rounded down**, so a project never shows 100% until every feature qualifies.
- With **no features**, both values are `null`, shown as "progress unknown", not 0% or 100%.
- Only explicit statuses count. Nothing is estimated or inferred.

**Needs attention** lists features that are `blocked`, have verification `failed`, or are `needs_verification`. Failed ones come first, then blocked, then those needing verification. Within each group the most recently updated comes first.

**Last activity** for a project is the most recent of its own `updated_at`, its features' `updated_at`, and its latest report.

## Web UI

Blade + Livewire 4 (class components in `app/Livewire`). The stylesheet is plain CSS in `public/css/app.css`, so there is no build step.
Everything the browser loads is served by Unseen itself, so the web UI and the CLI download work without internet
access: diagrams use a bundled copy of Mermaid 11.4.1 (`public/vendor/mermaid/11.4.1`, MIT licence alongside),
pinned with a subresource-integrity hash in `resources/views/livewire/feature-detail.blade.php`. The Outfit font (SIL OFL 1.1) is bundled in `public/vendor/fonts/outfit`. Users sign in by session with email and password, limited to 5 attempts per email and IP.

**The web UI is view only for tracking data.** Coding agents write features, categories, criteria, statuses,
checks, diagrams, reports and scan reviews through the API; trusted test runs verify. People use the web UI to
watch, and to manage projects, members and API tokens. The project and feature components have no actions that
change tracking data (not just hidden buttons), so they can't be called directly either.

| Path | Page |
|---|---|
| `/login` | Sign in |
| `/projects` | Project list with progress bars, items needing attention, last activity |
| `/projects/create`, `/projects/{id}/edit` | Project settings: details, people (add, remove, leave), archive or restore |
| `/projects/{id}/unresolved` | Unresolved work: open and closed items, and who or what closed each |
| `/projects/{id}/mismatches` | PRD vs design: tiles (your decision, client's decision, decided, applied), each mismatch with the PRD and design side by side and which was built, **Follow the PRD / Keep the design / Something else** buttons with a note, prompts to find mismatches and to apply decisions |
| `/projects/{id}/tickets` | Tickets: tickets per flow stage, open / done / all and status and type filters, latest QA or client comment on each, recent QA and client feedback |
| `/tickets/{id}` | Ticket: flow tracker (Open → In progress → QA → Client review → Done), details, linked feature, thread of comments and status changes with roles and linked reports |
| `/projects/{id}/roadmap` | Roadmap timeline: phases with status, target date (overdue flagged), progress, linked features' verification, and checklists |
| `/settings/tokens` | API tokens: create (agent, CI or full access, with expiry), last used, revoke |
| `/projects/{id}` | Overview: feature list (quick status filters, search), counts, status and activity charts, progress by category, needs attention, recent changes, reports, QA and client feedback, open tickets, open unresolved work, verification |
| `/features/{id}` | Feature detail: lifecycle tracker (Planned → In progress → Implemented → Verified), description and criteria, diagrams (flowchart, use case, sequence; rendered from Mermaid), timeline of status changes and reports, automated checks with latest results, repository evidence |
| `/projects/{id}/reports` | Report list |
| `/projects/{id}/suggestions` | Scan suggestions by review state: evidence, duplicate hints, the feature each became part of |
| `/test-runs/{id}` | Test run: totals, and per feature whether it was verified, failed, or why not |
| `/reports/{id}` | Report detail: summary, limitations, git refs, features, tests, unresolved work, changed files |

Keep tokens out of source control — use an environment variable or a local config file with `0600` permissions.

## Milestone status

- [x] **M1 Foundation** — projects CRUD + archive, token auth, owner/member authorization, API tests
- [x] **M2 Feature inventory** — categories, features, acceptance criteria, status rules + history
- [x] **M3 Dashboard**: dashboard API, documented progress calculation, Livewire web UI
- [x] **M4 Agent reports**: report API and UI, feature evidence, claim rules, manual report flow
- [x] **M5 Claude Code CLI**: [`packages/cli`](../packages/cli) with `init` / `status` / `report`, read+report-only tokens, secret masking
- [x] **M6 Repository scan**: local `unseen scan`, suggestions with evidence, review UI, duplicate-free rescans
- [x] **Team & operations**: project members, token management with expiry, closable unresolved work, category management
- [x] **PRD vs design**: mismatches recorded by agents, decided by people on the dashboard, applied by agents, `unseen mismatch`
- [x] **Tickets & feedback**: tickets with a build → QA → client review flow, QA and client comment threads, report links, dashboard cards, `unseen ticket`
- [x] **M7 Verification**: feature checks, JUnit test runs via `unseen verify`, documented verification policy, `verify` token ability

## Decisions

- **Web frontend: Blade + Livewire**, served by this Laravel app. It's the simplest well-supported Laravel option, with no separate JS build.
- **Members can edit** project details and features. Only owners can archive.
- **The project folder is per person** (`project_workspaces`): each member sets where the repo is on their own computer, and Proceed opens Claude Code only on the computer of whoever clicked it.
- **People decide PRD vs design mismatches in the web UI.** The only tracking data the web UI writes, because the
  decision must never be the agent's: agent tokens can record and apply mismatches but not decide them.
- **Tickets are recorded by the coding agent, like everything else.** People pass tickets and QA / client feedback to
  the agent, which records them with a role (`qa`, `client`…) saying whose words they are. The web UI stays view only.
  Agents move their own work only as far as `ready_for_qa`; later stages come from QA's or the client's verdict.
- **Contributors get their own token, not a shared one.** Invited contributors create an account and connect their own agent, so work is attributed per person and each token can be revoked alone.

Not built (deliberately out of scope for the private MVP): open self sign-up (accounts come from
`unseen:create-user` or a project invitation), and deployment configuration, which depends on the hosting choice.
