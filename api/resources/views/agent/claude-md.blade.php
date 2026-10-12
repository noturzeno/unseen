## Unseen

This repository is tracked in Unseen (`.unseen.json`): {!! $url !!}/projects/{!! $project->id !!}
Coding agents are the only writers of its Unseen data (several contributors' agents may share it). People only
view it, and only CI test runs verify features.

- Before starting a task, run `unseen status --json`. Use existing feature names and ids; read their
  acceptance criteria; note open unresolved items (with ids), failed verifications, and the roadmap's
  current phase and its open items related to the task.
- Keep the roadmap in sync with this repository's plan (phases and their implementation lists). Whenever
  the plan changes or you finish items, write the whole roadmap as JSON to a file outside the repo and run
  `unseen roadmap sync --file <file>`:
  `{"phases":[{"key":"M1","name":"Foundation","description":"…","target_date":"2026-12-01","items":["[x] Done item","[ ] Open item",{"text":"…","done":false,"feature":"<feature name or id>"}]}]}`
  Keep each phase's `key` stable. Phases you leave out are removed. Status is worked out from the items
  unless you set `"status"` (`planned`, `in_progress`, `done`, `blocked`). Link items to tracked features
  where they match. If the repository has a roadmap or plan file (ROADMAP.md, PLAN.md, milestones in the
  README or docs/), it is the source: keep it and Unseen the same. `unseen roadmap draft` builds the
  JSON from "Phase N" sections in the docs for you to check before syncing.
- When the project's docs change (PRD, implementation matrix, plan), update Unseen to match: new or
  changed requirements become features or feature updates (keep their ids), and status changes in the
  docs are checked against the code before you record them.
- If the task adds an untracked feature or changes what one does, record it with `unseen api` before
  reporting (it uses the stored token; never handle the token yourself). Never record a feature twice:
  check `unseen status --json` (or `unseen api GET /projects/{!! $project->id !!}/features`) first, and if a feature with
  the same or a similar name exists, update it with PATCH instead of creating a new one.
  - New feature: `unseen api POST /projects/{!! $project->id !!}/features --data '{"name":"…","description":"…","status":"in_progress","acceptance_criteria":["…"]}'`
  - Status: `unseen api PATCH /features/<id> --data '{"status":"implemented","status_note":"…"}'`
    (`implemented` only when fully built; `verified`/`failed` are refused, only test runs verify)
  - `ignored` features were dropped by a person: don't build them or change their status. If one seems
    needed after all, ask me.
  - Category: `unseen api POST /projects/{!! $project->id !!}/categories --data '{"name":"…"}'`, then `"category_id"` on the feature
@if (count($project->platformList()) > 1)
  - Platforms: this project ships on {!! collect($project->platformList())->map(fn ($p) => $p->value)->join(', ') !!}. Add `"platforms":["ios"]` (any of those) to a
    feature that exists on only some of them; leave it out for features on every platform (shared code, backend, API)
@endif
  - Tests that prove it: `unseen api POST /features/<id>/checks --data '{"pattern":"Tests\\Feature\\SomeTest"}'`
  - Diagram (where it helps): first check `unseen api GET /features/<id>/diagrams`; change an existing one with
    `unseen api PATCH /diagrams/<diagram id> --file <json>` instead of adding a copy. New one:
    `unseen api POST /features/<id>/diagrams --file <json outside the repo>` with
    `{"type":"flowchart|use_case|sequence","title":"…","definition":"<Mermaid>"}`. Draw use cases as `flowchart LR`
    with `Name[Customer]:::actor` actors, `([Use case])` pills inside a `subgraph`, and `classDef actor stroke-dasharray: 4 3`.
@if ($project->mismatches_enabled)
- PRD vs design: the build follows the designer's design, which can differ from the PRD. When you notice a
  difference (or I ask you to look for them), record it with `unseen mismatch add --file <json outside the repo>`:
  `{"key":"<req id>-<slug>","reference":"PRD §… · design: <screen>","feature":"<feature>","title":"…","prd_says":"…","design_says":"…","built_to":"design|prd|neither|not_built","built_note":"…","impact":"…","decide_by":"team|client"}`.
  Never pick a side yourself: I (or the client) decide on the dashboard. Before a task, check
  `unseen mismatch list --status decided` and carry out decisions that touch it (follow the PRD: change the
  code; keep the design: update the PRD), then `unseen mismatch applied <id> --note "…"`.
@endif
- Track tickets and their QA / client feedback with `unseen ticket` (`<ticket>` = its reference or `#id`):
  - When I give you a ticket, bug or change request, record it unless `unseen ticket list --all` has it:
    `unseen ticket add --title "…" --ref <REF> --type feature|bug|change_request|task --feature "<feature>" --raised-by client|qa|project_manager|developer --comment "<their words>"`.
    Then `unseen ticket move <ticket> in_progress` when you start.
  - When I pass on QA or client comments, record them as said, with whose words they are:
    `unseen ticket comment <ticket> --role qa|client --body "…"`, adding `--status qa_failed` (QA rejected it),
    `--status client_review` (QA passed), `--status changes_requested` (client wants changes) or `--status done`
    (client approved). Record only what people said; never invent or paraphrase approval.
  - When your work is ready to check, move it to `ready_for_qa` (never `done` yourself): list it in the report as
    `"tickets":[{"ref":"<REF>","status":"ready_for_qa","note":"…"}]`.
  - Keep names, emails and phone numbers out of comments: use the role, not the person.
- After finishing a task, write a report JSON to a temp file outside the repo and submit it with
  `unseen report --file <file>`. Include: a plain-English summary, `prompt` (what you were asked to do, in a
  sentence, without secrets or personal data), every feature you changed (status
  only if it changed), changed files, every test command you actually ran with its real outcome
  (`not_run` for relevant suites you didn't run), anything left unfinished in `unresolved`, open item ids
  you fully completed in `resolves`, honest `limitations` and `confidence`.
- Put one thing per `unresolved` entry and say who has to act:
  `{"text":"…","waiting_on":"client|team|agent","decision_note":"<exactly what is needed>"}`. `client`: the client
  must decide, confirm or provide something; `team`: I or my team must decide or provide access, keys or a manual
  step; `agent`: no decision needed. Sort older items with
  `unseen api PATCH /unresolved/<id> --data '{"waiting_on":"…","decision_note":"…"}'`. Never work on an item waiting
  on the client or the team until I give you the decision. An item with a `decision` was decided by a person in
  Unseen: carry out that decision as written.
- After adding or removing routes, models, pages, components or tests, run `unseen scan`, then review
  the pending suggestions (`unseen api GET /projects/{!! $project->id !!}/suggestions`) and accept, merge or
  dismiss each (`unseen api POST /suggestions/<id>/accept`, `/merge` with `{"feature_id":…}`, `/dismiss`).
- Never claim or imply verification.
- Never put secrets, `.env` values, connection strings or personal data in anything you send to Unseen.
- If the CLI reports an error you can't fix by correcting your input (not linked, not authenticated,
  not allowed, unreachable), stop and tell me. Don't retry with other credentials.
- Tell me the report id and URL the CLI prints.
