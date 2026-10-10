# 6. Development reports

A **development report** records what a coding agent (or a person) says was done: summary, features touched,
files changed, tests run, what's left open, and honest limitations.

> Reports are **claims**. They can move a feature to *implemented*, but they never make it *verified*.

## Option A: Claude Code reports through the CLI

If you followed [guide 5](05-claude-code-cli.md), Claude Code does this on its own. To do it yourself:

1. Write a file, e.g. `report.json`:
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
2. Check what would be sent (nothing is sent):
   ```bash
   unseen report --file report.json --dry-run
   ```
3. Send it:
   ```bash
   unseen report --file report.json
   ```
   ```
   Report #12 saved: http://127.0.0.1:8000/reports/12
     #4 Password reset: claimed implemented
     #2 Login
   Reports do not verify features. Someone needs to check them in Unseen.
   ```

**Field guide**

| Field | Required | What to put |
|---|---|---|
| `summary` | ✅ | What was done, in plain English |
| `agent` | | e.g. `claude-code` |
| `confidence` | | `low`, `medium` or `high`: the reporter's own view |
| `limitations` | | What wasn't tested, assumptions made |
| `features` | | Feature names (as in `unseen status`) or `#ids`; optionally with `status` and `note` |
| `changed_files` | | File paths (or `{ "path": …, "change": "added" \| "modified" \| "deleted" \| "renamed" }`) |
| `tests` | | `{ "command": …, "outcome": "passed" \| "failed" \| "error" \| "skipped" \| "not_run" }` |
| `unresolved` | | Things left to do. Each becomes an open item on the dashboard |
| `resolves` | | `#ids` of open unresolved items this work finished |

Git branch and commit are added automatically (use `--no-git` to skip).
Quick one-liner: `unseen report --summary "Fixed typo in footer"`.

## What a report does to features

| The report says | Effect |
|---|---|
| Feature claimed **implemented** | Status → Implemented; verification → **Needs verification** |
| Feature listed with no status | Nothing changes; the report is linked as evidence |
| Feature was **Verified** or **Failed** before | Verification → **Needs verification** (the code changed since it was checked) |
| `unresolved` items | Each becomes an open item on the dashboard |
| `resolves` ids | Those items are closed, recorded as the reporter's claim |

## Reading a report

Open the project → **Reports** → click a report. You'll see the summary, limitations, git details, features
(with what was claimed), tests (*as reported*, not re-run by Unseen), unresolved items (open or done), and
changed files.

Each feature page also lists **Reports mentioning this feature**.

> Reports can't be edited or deleted after they're saved. They are the audit trail.

## Secrets and personal data

The CLI masks things that look like secrets (API keys, tokens, `PASSWORD=…`, credentials in URLs) before
sending, and tells you which fields it changed. It's a safety net, not a guarantee. **Never put secrets,
`.env` contents, or personal data (client staff details, ID numbers and the like) in a report.**
See [guide 9](09-tokens-and-security.md).

## What's next

→ [7. Repository scan](07-repository-scan.md)
