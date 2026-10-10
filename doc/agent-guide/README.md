# Unseen guide for coding agents

**Audience:** AI coding agents (Claude Code and similar) working in a repository that contains `.unseen.json`.
**Purpose:** keep the project's feature inventory accurate. You are its only writer: people view it in the web app,
and only trusted test runs verify.

> Humans setting this up: see [user guide 5](../user-guide/05-claude-code-cli.md) and paste one of the
> [instruction snippets](instructions-snippet.md) into the repository's `CLAUDE.md` or `AGENTS.md`.

---

## 1. Rules

1. **Read before you work.** Run `unseen status --json` at the start of every task.
2. **Report after you work.** Submit one report per completed task with `unseen report`.
3. **Never claim verification.** You cannot mark anything verified or failed, and you must not say or imply that
   you did. Verification comes only from CI test runs.
4. **Report only what happened.** Tests you didn't run are `not_run`. Tests that failed are `failed`. Don't round up.
5. **Claim `implemented` only when the behaviour is fully built.** If anything is missing, use `in_progress`, and list the gap in `unresolved`.
6. **Use existing feature names.** Match features from `status`. Create a feature only when nothing tracked
   matches; never invent near-duplicates.
7. **No secrets, no personal data.** Never include passwords, API keys, tokens, `.env` values, connection strings,
   or real people's personal data (names, emails, phone numbers, ID numbers, dates of birth, health or payment data)
   in any report field.
8. **Don't work around the permissions.** If the CLI says something is not allowed, stop and tell the user. Don't try other endpoints or tokens.

## 2. What you can and cannot do

You are the **only writer** of this project's tracking data: the web app is view only for people. Your token
(created by `unseen init`) has `read`, `report` and `record`, and allows:

| ✅ Allowed | ❌ Not allowed |
|---|---|
| `unseen status` (read project, features, open work) | Mark features `verified` or `failed` (only CI test runs can) |
| `unseen report` (submit development reports) | Submit test runs (`unseen verify`) |
| `unseen scan` (submit scan suggestions) | Delete features (set them `not_implemented` with a note instead) |
| `unseen api …` to create and update features, categories, acceptance criteria, statuses, checks and diagrams, and to accept, merge or dismiss suggestions ([section 3a](#3a-recording-features-with-unseen-api)) | List, create or revoke API tokens |
| Close open unresolved items through a report (`resolves`) | Change projects or members (people do that) |
| `unseen mismatch …` to record PRD vs design differences and mark decided ones applied ([section 3b3](#3b3-prd-vs-design-mismatches)) | Decide a PRD vs design mismatch, or pick a side in code or docs before a person decides |
| `unseen ticket …` to record tickets, move them through the flow, and record QA and client comments ([section 3c](#3c-tickets-and-qa--client-feedback)) | Invent feedback, or mark a ticket `done` without the client's approval |

## 3. Workflow

### Before starting a task

```bash
unseen status --json
```

Use the output to:
- find the features your task touches (match on `name`, `description`, `acceptance_criteria`)
- read their `acceptance_criteria`, which define "done"
- note open `unresolved` items (with `id`) your task may complete
- note `needs_attention` items related to your task (e.g. a `failed` verification you may be fixing)

### While working

Keep track of:
- each feature you change, and whether it is now fully built
- files you changed (paths relative to the repository root)
- every test command you ran, **with its actual result**
- anything you didn't finish, couldn't test, or assumed

### After finishing

0. If the task added a feature nobody tracks yet, or changed what a tracked feature does, record it first with
   `unseen api` ([section 3a](#3a-recording-features-with-unseen-api)): create or update the feature, its
   acceptance criteria and, where it helps, a diagram; link the tests that prove it with a check.

1. Write the report JSON to a temporary file **outside the repository** (or pipe it on stdin), e.g. `/tmp/unseen-report.json`.
2. Optional: preview with `unseen report --file /tmp/unseen-report.json --dry-run`.
3. Submit: `unseen report --file /tmp/unseen-report.json`.
4. Check the exit code and output ([section 7](#7-errors-and-what-to-do)).
5. If you added or removed routes, controllers, models, pages or tests, also run `unseen scan`.
6. Tell the user the report id and URL printed by the CLI.

**One task = one report.** Don't report partial steps, and don't merge unrelated tasks into one report.

## 3a. Recording features with `unseen api`

`unseen api <METHOD> <path>` sends one request to the linked Unseen (`/api/v1`) with the stored token and
prints the JSON response. You never see or handle the token. Body: `--data '<json>'`, or `--file <path>` (write
the file outside the repository), or `--file -` for stdin. Likely secrets in the body are masked, but the same
data-safety rules apply as for reports ([section 10](#10-data-safety-checklist-run-through-before-every-submit)).

| To | Request |
|---|---|
| Create a category | `unseen api POST /projects/<id>/categories --data '{"name":"Billing"}'` |
| Create a feature | `unseen api POST /projects/<id>/features --data '{"name":"Invoice download","description":"Customers can download past invoices as PDF.","category_id":4,"status":"in_progress","acceptance_criteria":["Every paid invoice has a Download button","The PDF matches the invoice total"]}'` |
| Tag its platforms | `unseen api PATCH /features/<id> --data '{"platforms":["ios"]}'`. Use the project's platforms (`ios`, `android`, `web`); `[]` or leaving it out means every platform |
| Change its status | `unseen api PATCH /features/<id> --data '{"status":"implemented","status_note":"Built in task #123"}'` |
| Replace its criteria | `unseen api PATCH /features/<id> --data '{"acceptance_criteria":["…","…"]}'` |
| Link the tests that prove it | `unseen api POST /features/<id>/checks --data '{"pattern":"Tests\\Feature\\InvoiceDownloadTest"}'` |
| Add a diagram | `unseen api POST /features/<id>/diagrams --file /tmp/diagram.json` with `{"type":"sequence","title":"Download flow","definition":"sequenceDiagram\n    …"}`. `type` is `flowchart`, `use_case` or `sequence`; `definition` is Mermaid |
| Update a diagram | `unseen api PATCH /diagrams/<id> --data '{"definition":"…"}'` |
| Review a scan suggestion | `unseen api POST /suggestions/<id>/accept` (or `/merge` with `{"feature_id":…}`, or `/dismiss`) |

Rules:
- Statuses follow rule 5: `implemented` only when fully built. Setting `implemented` moves verification to
  `needs_verification` automatically. `verification_status` can only be `not_checked` or `needs_verification`;
  `verified` and `failed` are refused (422).
- Use cases have no native Mermaid diagram: draw them as `flowchart LR` with actors as `Name[Customer]:::actor`
  boxes and use cases as `([Pay invoice])` pills inside a `subgraph`, and add `classDef actor stroke-dasharray: 4 3`.
- Keep diagrams about behaviour, with no secrets, real customer data or internal hostnames.
- Everything you write is shown as *Reported by an agent*.

## 3b. Keeping the roadmap in sync

Unseen shows the project's roadmap: phases (milestones, releases) and each phase's implementation list. The
repository's own plan is the source: ROADMAP.md, PLAN.md, or milestones in the README or docs. Whenever the plan
changes or you finish items in it, write the **whole** roadmap as JSON to a file outside the repository and run:

```bash
unseen roadmap sync --file /tmp/unseen-roadmap.json
```

```json
{ "phases": [
  { "key": "M1", "name": "Foundation", "description": "Sign-in and projects", "target_date": "2026-12-01",
    "items": ["[x] Sign in", "[ ] Invite colleagues", { "text": "Password reset", "done": true, "feature": "Password reset" }] }
] }
```

Rules:
- Keep each phase's `key` stable between syncs; phases you leave out are removed.
- Tick an item (`[x]` / `"done": true`) only when it is fully built, same as `implemented` (rule 5).
- Link items to tracked features where they match (`feature`: name or id), so the dashboard can show whether
  "done" items are actually verified.
- Leave `status` out unless a phase is `blocked`; it is worked out from the items.
- Don't invent phases. If the repository has no plan, ask the user before creating one.
- `unseen status --json` shows the roadmap (`current_phase`, each phase's open items) at the start of a task.

## 3b2. Who an open item waits on

People need to see at a glance which open items wait on **their** decision, which wait on **the client**, and which
the agent can just do. Give every `unresolved` entry a `waiting_on` (`client`, `team`, `agent`) and a
`decision_note` saying exactly what is needed, and keep one item per entry (don't list ten gaps with different
owners in one string). To sort items that are already open:

```bash
unseen api GET "/projects/<id>/unresolved?waiting_on=unsorted"
unseen api PATCH /unresolved/<id> --data '{"waiting_on":"client","decision_note":"Confirm whether business users may reply to listings (BUS-04)."}'
```

A mixed item is split with a report: one `unresolved` entry per part, and the mixed item's id in `resolves`.
Never work on an item waiting on `client` or `team`, and never guess the decision, until the user gives it to you.

## 3b3. PRD vs design mismatches

The product is often built from the designer's design, which can differ from the PRD. Each difference needs a
person's decision, so record it and never resolve it yourself.

```bash
unseen mismatch add --file /tmp/mismatches.json     # one object or a list; --dry-run to preview
unseen mismatch list --status decided                # decisions people made, for you to carry out
unseen mismatch applied 3 --note "Added the available-codes list above the text box"
```

```json
[{ "key": "PRM-01-promo-list", "reference": "PRD §4.11 PRM-01 · design: Checkout v3",
   "feature": "PRM-01 View available and eligible promo codes",
   "title": "Promo codes: list of available codes vs. a single text box",
   "prd_says": "Users shall view available and eligible promo codes and enter a code during checkout.",
   "design_says": "Checkout has one 'Promo code' text box with Apply; no list.",
   "built_to": "design", "built_note": "Text box only; the list API exists but the page doesn't call it.",
   "impact": "Following the PRD adds a list of chips above the box.", "decide_by": "client" }]
```

Rules:
- Record real conflicts in behaviour, fields, steps, rules or content, not styling (unless the PRD specifies it).
- `key` keeps re-recording idempotent (same key = update). `built_to` is `design`, `prd`, `neither` or `not_built`.
- `decide_by`: `client` when it changes what the client asked for or signed off, otherwise `team`.
- **Never decide.** Agent tokens can't (`decide` needs `write`), and you must not change code or the PRD to pick
  a side. Applying an undecided mismatch is refused.
- Decided: *Follow the PRD* → change the code, with tests. *Keep the design* → update the PRD text in the
  repository. *Something else* → follow the note and update the PRD. Then mark it `applied` with what you changed.
- `unseen status --json` lists them under `mismatches` (`to_apply`, `waiting_for_decision`).

## 3c. Tickets and QA / client feedback

Agencies work from tickets: a client asks for a feature or reports a bug, a developer (you) builds it, QA tests it,
the client reviews it. Unseen shows each ticket's place in that flow and its thread of QA and client comments on
the dashboard. You record all of it with `unseen ticket`, because people pass tickets and feedback to you.

```
open → in_progress → ready_for_qa ─┬→ client_review ─┬→ done
                        ↑           └→ qa_failed      └→ changes_requested
                        └──────────── (fix, then back to ready_for_qa) ┘          closed = dropped
```

| When | Run |
|---|---|
| You are given a ticket not yet in `unseen ticket list --all` | `unseen ticket add --title "Pay button does nothing on Safari" --ref WEB-142 --type bug --priority high --feature "Checkout" --raised-by client --comment "Tapping Pay on iPhone does nothing."` |
| You start work | `unseen ticket move WEB-142 in_progress` |
| Your work is ready to test | list it in the report: `"tickets": [{"ref": "WEB-142", "status": "ready_for_qa", "note": "Bound the handler to touchend too"}]` (or `unseen ticket move WEB-142 ready_for_qa --note "…"`) |
| QA's findings are passed to you | `unseen ticket comment WEB-142 --role qa --body "Still fails on iOS 17" --status qa_failed` (passed QA: `--status client_review`) |
| The client's comments are passed to you | `unseen ticket comment WEB-142 --role client --body "Button colour should match the brand" --status changes_requested` (approved: `--status done`) |
| A general remark with no status change | leave out `--status` |
| Read a ticket and its thread | `unseen ticket show WEB-142` (`--json` for the full thread) |

Rules:
- `--role` says **whose words** the comment is: `qa`, `client`, `developer`, `project_manager`, or `agent` (yours).
  Record what they said, close to their wording. Never invent feedback or approval.
- Move your own work only as far as `ready_for_qa`. `client_review`, `done` and `qa_failed` come from QA's or the
  client's verdict as it was passed on to you. `done` on a ticket is the client's sign-off, not verification:
  features still need test runs to be verified.
- Use roles, not people: no names, emails, phone numbers or ID numbers in comments. The CLI masks emails, phone
  and HKID numbers, but don't rely on it. `--author` is only for a work role label such as "QA lead".
- Link the ticket to the feature it changes (`--feature`), so the feature page shows its tickets.
- `<ticket>` is the reference (`WEB-142`, case-insensitive) or `#id`. Long feedback can come from a file outside
  the repository: `--file /tmp/feedback.txt` or `--file -`.
- `unseen status --json` lists open tickets (sent-back ones first) with their latest QA or client comment under `tickets`.

## 4. Reading `unseen status --json`

```json
{
  "project": { "id": 3, "name": "Client Portal", "description": "…", "url": "https://…/projects/3" },
  "summary": {
    "total": 24,
    "by_status": { "planned": 5, "in_progress": 3, "implemented": 14, "blocked": 1, "not_implemented": 1 },
    "by_verification_status": { "not_checked": 6, "needs_verification": 9, "verified": 8, "failed": 1 },
    "implemented_and_verified": 8,
    "needs_attention": 11,
    "progress": { "implemented_percent": 58, "verified_percent": 33 },
    "open_unresolved": 4
  },
  "needs_attention": [ { "id": 7, "name": "Invoice export", "status": "implemented", "verification_status": "failed" } ],
  "unresolved": [ { "id": 31, "text": "Rate limiting on reset requests", "report_id": 12, "reported_at": "…" } ],
  "features": [
    {
      "id": 4,
      "name": "Password reset",
      "category": "Authentication",
      "status": "in_progress",
      "verification_status": "not_checked",
      "description": "Users who forget their password can get a reset link by email.",
      "acceptance_criteria": ["Reset link expires after 60 minutes", "Old password stops working after reset"]
    }
  ]
}
```

The `summary` also includes other fields (e.g. `reports`, `pending_suggestions`); ignore any you don't need.

**Status meanings**

| `status` | Meaning | | `verification_status` | Meaning |
|---|---|---|---|---|
| `planned` | Not started | | `not_checked` | Nobody checked |
| `in_progress` | Partly built | | `needs_verification` | Built or changed; awaiting a check |
| `implemented` | Fully built | | `verified` | Confirmed working by a person or CI |
| `blocked` | Can't proceed | | `failed` | Checked and not working |
| `not_implemented` | Missing or dropped | | | |

## 5. The report format

Minimal valid report:
```json
{ "summary": "Fixed the typo in the footer copyright line." }
```

Full report:
```json
{
  "summary": "Added the password reset flow: request form, emailed signed link, reset form, and password update.",
  "agent": "claude-code",
  "confidence": "medium",
  "limitations": "Email sending was tested with the log mail driver only, not a real mail server. Link expiry was tested with a mocked clock.",
  "features": [
    { "name": "Password reset", "status": "implemented", "note": "All acceptance criteria covered by feature tests." },
    { "id": 2, "note": "Login page now links to the reset form; login behaviour unchanged." }
  ],
  "changed_files": [
    { "path": "app/Http/Controllers/PasswordResetController.php", "change": "added" },
    { "path": "routes/web.php", "change": "modified" },
    "resources/views/auth/forgot-password.blade.php"
  ],
  "tests": [
    { "command": "php artisan test --filter=PasswordReset", "outcome": "passed", "summary": "6 tests, 14 assertions" },
    { "command": "php artisan test", "outcome": "passed" },
    { "command": "npm run e2e", "outcome": "not_run", "summary": "No browser available in this environment" }
  ],
  "unresolved": ["Rate limiting on password reset requests is not implemented."],
  "resolves": [31]
}
```

The formal definition is in [`report.schema.json`](report.schema.json).

| Field | Type | Rules |
|---|---|---|
| `summary` | string, **required** | ≤ 10,000 chars. What changed, in plain English a non-developer can follow. |
| `agent` | string | ≤ 100 chars. Use `claude-code` (or your tool's name). |
| `confidence` | `low` \| `medium` \| `high` | Your honest view of the work's correctness. See below. |
| `limitations` | string | ≤ 10,000 chars. What you didn't test, assumptions, known risks. Strongly recommended. |
| `features` | array, ≤ 100 | Each item: a feature **id** (number), an exact feature **name** (string, case-insensitive), or an object `{ "id" or "name", "status"?, "note"? }`. `note` ≤ 2,000 chars. |
| `features[].status` | `planned` \| `in_progress` \| `implemented` \| `blocked` \| `not_implemented` | Only set it if the status actually changed. Omit it to just record that you touched the feature. |
| `changed_files` | array, ≤ 2,000 | Path string, or `{ "path", "change"? }` with `change` = `added` \| `modified` \| `deleted` \| `renamed`. Paths relative to the repo root, ≤ 1,024 chars. |
| `tests` | array, ≤ 200 | `{ "command", "outcome", "summary"? }`. `outcome` = `passed` \| `failed` \| `error` \| `skipped` \| `not_run`. `command` ≤ 1,000 chars, `summary` ≤ 5,000. |
| `unresolved` | array, ≤ 100 | One concrete item per entry: a string, or `{ "text", "waiting_on"?, "decision_note"? }`. `waiting_on` = `client` (the client must decide, confirm or provide something) \| `team` (the user or their team must decide, or provide access, keys or a manual step) \| `agent` (no decision needed). `decision_note` says exactly what is needed. Always set `waiting_on`: the dashboard groups open items by it. `text` ≤ 2,000 chars. |
| `resolves` | array of integers, ≤ 200 | `id`s from `status.unresolved` that your work **fully** completed. |
| `tickets` | array, ≤ 100 | Reference (`"WEB-142"`), id, or `{ "ref" \| "id", "status"?, "note"? }`. Usually `"status": "ready_for_qa"` ([section 3c](#3c-tickets-and-qa--client-feedback)). |
| `git_branch`, `git_commit` | string | Filled in automatically; don't set them. |
| `diff_ref` | string | Optional link to a PR or diff, ≤ 2,048 chars. |
| `reported_at` | ISO 8601 date-time | Optional; defaults to now; can't be in the future. |

Any other top-level field is **rejected**, including any attempt to set verification.

### Choosing values honestly

**Feature `status`:**
| Situation | Use |
|---|---|
| Every acceptance criterion is met and the behaviour works end to end | `implemented` |
| Some of it is built | `in_progress`, plus an `unresolved` entry for what's missing |
| You started but hit something you can't resolve (missing credentials, unclear requirement, external dependency) | `blocked`, with the reason in `note` |
| You only refactored or touched it without changing its completeness | omit `status`; add a `note` |
| You found it doesn't exist although Unseen says `implemented` | `not_implemented`, with evidence in `note` |

**`confidence`:**
| Value | Use when |
|---|---|
| `high` | Change is small or well covered by tests you ran and that passed |
| `medium` | Main paths tested; some edges or integrations untested |
| `low` | Little or no testing possible, or you're unsure of the requirement |

**`tests`:** list each command you actually ran, once, with its real outcome. If a relevant suite exists but you
didn't run it, list it as `not_run` with the reason in `summary`. Never write `passed` for a command you didn't run.

## 6. What your report does

| You report | Unseen does |
|---|---|
| A feature with `"status": "implemented"` | Sets it implemented and **needs_verification** (never verified) |
| A feature that was `verified` or `failed` | Sets it back to **needs_verification**, because your change invalidates the old check |
| A feature with no `status` | No status change; your report is linked as evidence |
| `unresolved` entries | Each becomes an open item on the project dashboard |
| `resolves` ids | Those items are closed, marked as closed by your report |
| `tickets` | Each ticket gets a thread entry linked to your report, moved to the `status` you give, with your `note` |
| `tests` | Shown as *reported*. They do **not** verify anything. |

Reports cannot be edited or deleted after submission. Get them right before sending; use `--dry-run` if unsure.

## 7. Errors and what to do

Exit codes: **0** success · **1** error (fix input or tell the user) · **2** wrong command usage.

| Message contains | What to do |
|---|---|
| `not linked to Unseen` | Tell the user the repository needs `unseen init`. Don't run init yourself unless asked; it needs their login. |
| `No Unseen token` / `Not authenticated` | The token is missing or expired. Tell the user to run `unseen init --force`. |
| `Not allowed` | You lack permission. Stop; tell the user. Don't retry with other tokens. |
| `no feature named "…"` | Run `unseen status --json`, use the exact name or id. If the feature truly doesn't exist, remove it from `features` and add an `unresolved` entry like *"Feature 'X' is not tracked in Unseen"*. |
| `matches several features (ids …)` | Use the correct id instead of the name. |
| `Unknown report field(s)` | Remove those fields. Only the fields in section 5 are allowed. |
| `Masked what looked like secrets in: …` | Not fatal: the report was sent with the value masked. Tell the user which field, and avoid including such values in future. |
| Validation errors listed per field (e.g. `tests.0.outcome: …`) | Fix the listed fields and resubmit once. |
| `Could not reach Unseen` | Tell the user; keep the report file so it can be sent later. Don't silently skip reporting. |

Never retry a successful submission: each submit creates a new, permanent report.

## 8. Good and bad reports

**❌ Bad**
```json
{
  "summary": "Implemented everything. All tests pass. Verified working.",
  "features": ["Password Reset Feature", "New: Remember me checkbox"],
  "tests": [{ "command": "tests", "outcome": "passed" }],
  "limitations": "None"
}
```
Problems: vague summary; claims verification; invented feature names; a test command that wasn't run as written;
no limitations. Also, a new feature can't be created through a report.

**✅ Good**
```json
{
  "summary": "Added a 'Remember me' checkbox to the login form. When ticked, the session lasts 30 days instead of until the browser closes.",
  "agent": "claude-code",
  "confidence": "high",
  "limitations": "Did not test across browser restarts manually; covered by a feature test that inspects the remember cookie.",
  "features": [{ "name": "Login", "note": "Added remember-me option; existing login behaviour unchanged." }],
  "changed_files": ["resources/views/auth/login.blade.php", "app/Livewire/Login.php", "tests/Feature/LoginTest.php"],
  "tests": [{ "command": "php artisan test --filter=LoginTest", "outcome": "passed", "summary": "5 tests" }],
  "unresolved": ["'Remember me' is not tracked as its own feature in Unseen; a person may want to add it."]
}
```

## 9. Scanning

Run `unseen scan` after you add or remove routes, controllers, models, migrations, pages, components or tests.
It sends only file paths and names, plus requirement rows from the project's docs (id, wording, section, status),
never whole files, and creates **suggestions**. In a documented project the docs' requirements are the feature
list: accept them keeping the requirement id, and check the doc's status against the code first. The scan itself never
creates features: review the pending suggestions afterwards (`unseen api GET /projects/<id>/suggestions`) and
accept, merge or dismiss each one ([section 3a](#3a-recording-features-with-unseen-api)). Running it repeatedly
is safe. Mention in your reply to the user that a scan was submitted.

## 10. Data safety checklist (run through before every submit)

- [ ] No passwords, keys, tokens, `.env` values or connection strings, in any field, including test `command` and `summary`.
- [ ] No real personal data. Use placeholders like `user@example.com` if an example is needed.
- [ ] No pasted file contents or large logs. Summarise instead.
- [ ] Paths are repository-relative, not your home directory.
- [ ] The report file is outside the repository, or will be deleted, so it isn't committed.

If you notice a secret or personal data has already been sent, tell the user immediately so they can contact
**it@visibleone.com** and rotate the secret.
