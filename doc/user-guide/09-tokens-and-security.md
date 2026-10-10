# 9. API tokens and data safety

## Which token for which job

Tokens let tools act as you. Give each tool **its own token with only the access it needs**.

| Token type (API tokens page) | Can | Cannot | Use for |
|---|---|---|---|
| **Coding agent (CLI)** | Read projects; record features, categories, criteria, statuses, checks and diagrams; review suggestions; send reports and scans | Verify; change projects, members or tokens | Claude Code, `unseen init` |
| **CI test results** | Read projects; send test runs (can verify or fail features) | Edit anything, send reports | CI pipelines running `unseen verify` |
| **Full access** | Everything, including projects, members, tokens and verification | n/a | Rare: your own scripts. Never give it to an agent |

`unseen init` creates a *Coding agent* token automatically.

## Create a token

1. Click **API tokens** in the top bar.
2. Enter a **Name** that says where it's used (e.g. `Claude Code – MacBook`, `CI – client-portal`).
3. Choose **Access** and **Expires after (days)**. 90 is suggested; avoid "never".
4. Click **Create token**, then **copy the value immediately**. It's shown only once.
5. Store it in:
   - an environment variable: `export UNSEEN_TOKEN=...` (not in a file inside the repository), or
   - your CI's secret store.
6. Click **I've copied it**.

## Review and revoke tokens

The **API tokens** page lists each token's access, **last used** time and expiry.

Revoke a token (**Revoke** → confirm) when:
- a laptop is lost or replaced
- someone leaves the team or the project
- a token may have been exposed (pasted in chat, committed, shown on screen)
- it hasn't been used for a long time

Expired tokens stop working at once and are deleted automatically the next day.

## If a token leaks

1. **Revoke it immediately** on the API tokens page.
2. Create a replacement and update wherever it's used (`unseen init --force` for the CLI; the CI secret for CI).
3. Check the project's recent **Reports**, **Test runs** and feature **History** for anything unexpected around that time.
4. Report it to the IT team at **it@visibleone.com**.

## Where credentials live

| What | Where | Safe to commit? |
|---|---|---|
| `.unseen.json` (URL + project id) | Repository root | ✅ Yes |
| CLI token | `~/.config/unseen/credentials.json` (private file permissions), one per project, or `UNSEEN_TOKEN` | ❌ Never |
| CI token | CI secret store | ❌ Never |
| Your password | Only in your head or password manager | ❌ Never |

The CLI refuses to send tokens over plain `http://` to anything except your own machine, and warns if the
credentials file can be read by other users.

## Keep personal data and secrets out of Unseen

Unseen stores what you and your agents send it. Hong Kong's Personal Data (Privacy) Ordinance (PDPO) applies to
any personal data in it. Keep it to **work information about features**:

**Don't put in features, reports, notes or test output:**
- passwords, API keys, tokens, `.env` contents, database connection strings
- client end-users' personal data: names, phone numbers, emails, HKID or passport numbers, dates of birth, health or payment details
- production data or database dumps

**Do:**
- describe features in general terms ("Customers can update their address")
- use made-up examples ("user@example.com")
- check `--dry-run` output before sending a report if you're unsure

The CLI masks values that **look like** secrets before sending reports and test failure messages, and tells you
which fields it changed. This catches common mistakes, not everything.

If personal data or a secret does get into Unseen, tell the IT team (**it@visibleone.com**). Reports can't be
edited through the app, so removing it needs an administrator. For questions about what you may store, ask the IT
team. This guide doesn't replace company policy.

## What's next

→ [10. Administration](10-administration.md)
