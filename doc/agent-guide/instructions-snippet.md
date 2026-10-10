# Instruction snippets for coding agents

Paste one of these into the repository's agent instruction file. Commit `.unseen.json` too.

> The web app's **Connect a coding agent** prompt includes a version of the Claude Code snippet with the
> project's id filled in (`api/resources/views/agent/claude-md.blade.php`); keep the two in step.

## For Claude Code: add to `CLAUDE.md`

```markdown
## Unseen

This repository is tracked in Unseen (`.unseen.json`). Full guide: <Unseen repo>/doc/agent-guide/README.md

- Before starting a task, run `unseen status --json`. Use existing feature names and ids; read their
  acceptance criteria; note open unresolved items (with ids) and failed verifications related to the task.
- After finishing a task, write a report JSON to a temp file outside the repo and submit it with
  `unseen report --file <file>`. Include: a plain-English summary, `prompt` (what you were asked to do, in a
  sentence, without secrets or personal data), every feature you changed (status
  only if it changed: `implemented` only when fully built, else `in_progress`), changed files, every test
  command you actually ran with its real outcome (`not_run` for relevant suites you didn't run), anything
  left unfinished in `unresolved`, open item ids you fully completed in `resolves`, honest `limitations`
  and `confidence`.
- Put one thing per `unresolved` entry and say who has to act:
  `{"text":"…","waiting_on":"client|team|agent","decision_note":"<exactly what is needed>"}`. `client`: the client
  must decide, confirm or provide something; `team`: I or my team must decide or provide access, keys or a manual
  step; `agent`: no decision needed. Sort older items with
  `unseen api PATCH /unresolved/<id> --data '{"waiting_on":"…","decision_note":"…"}'`. Never work on an item waiting
  on the client or the team until I give you the decision.
- PRD vs design: the build follows the designer's design, which can differ from the PRD. When you notice a
  difference (or I ask you to look for them), record it with `unseen mismatch add --file <json outside the repo>`:
  `{"key":"<req id>-<slug>","reference":"PRD §… · design: <screen>","feature":"<feature>","title":"…","prd_says":"…","design_says":"…","built_to":"design|prd|neither|not_built","built_note":"…","impact":"…","decide_by":"team|client"}`.
  Never pick a side yourself: I (or the client) decide on the dashboard. Before a task, check
  `unseen mismatch list --status decided` and carry out decisions that touch it (follow the PRD: change the
  code; keep the design: update the PRD), then `unseen mismatch applied <id> --note "…"`.
- Never claim or imply verification. Only CI test runs verify features.
- Never put secrets, `.env` values, connection strings or personal data in a report.
- You are the only writer of this project's Unseen data (people only view it). If the task adds an
  untracked feature or changes what one does, record it with `unseen api` (feature, acceptance criteria,
  status, checks for its tests, and a diagram where it helps). See section 3a of the guide.
- Track the tickets you work on and the QA / client feedback you are given with `unseen ticket`
  (section 3c of the guide): `add` a ticket when given one, `move` it to `in_progress`, record feedback with
  `unseen ticket comment <ticket> --role qa|client --body "…" [--status qa_failed|client_review|changes_requested|done]`,
  and list it in the report with `"status":"ready_for_qa"` when your work is ready. Never mark a ticket `done`
  unless the client's approval was passed on to you. No names, emails or phone numbers in comments.
- After adding or removing routes, models, pages, components or tests, run `unseen scan`.
- If the CLI reports an error you can't fix by correcting the report (not linked, not authenticated,
  not allowed, unreachable), stop and tell me. Don't retry with other credentials.
- Tell me the report id and URL the CLI prints.
```

## For other agents: add to `AGENTS.md` (Codex, Cursor, Copilot agents and others)

```markdown
## Unseen feature tracking

This repository reports work to Unseen using the `unseen` CLI (config in `.unseen.json`).

1. At the start of each task run `unseen status --json` and use its feature names/ids and acceptance criteria.
2. At the end of each task submit one report: `unseen report --file <report.json>`.
   Report format: <Unseen repo>/doc/agent-guide/report.schema.json.
   Set `"agent"` to your tool's name.
3. Report honestly: claim `implemented` only when fully built; list only tests you ran, with real outcomes;
   use `not_run` otherwise; put unfinished work in `unresolved`, one item each, as
   `{"text", "waiting_on": "client|team|agent", "decision_note"}` so people see who has to decide.
4. Never claim verification. Never include secrets or personal data.
5. Run `unseen scan` after adding or removing routes, models, pages or tests.
6. Record tickets and the QA / client comments you are given with `unseen ticket add|move|comment`
   (`--role qa|client`); move your own work only as far as `ready_for_qa`.
7. Record PRD vs design differences with `unseen mismatch add --file <json>` and never pick a side; carry out
   decided ones (`unseen mismatch list --status decided`) and mark them `unseen mismatch applied <id>`.
8. On permission or authentication errors, stop and ask the user.
```
