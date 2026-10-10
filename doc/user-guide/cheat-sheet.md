# Cheat sheet

## Web app

| I want to… | Go to |
|---|---|
| Start a project | Projects → **New project** |
| Add a colleague | Project → **Settings** → People → **Add member** (owner only) |
| See which tests prove a feature | Feature → **Automated checks** |
| See scan results and how agents reviewed them | Project → **Suggestions** |
| Read what an agent did | Project → **Reports** |
| Create or revoke a token | Top bar → **API tokens** |

## CLI

```bash
unseen init                                  # link repo (once)
unseen init --force                          # relink / renew token
unseen status                                # what exists, what's open
unseen status --json                         # same, for agents
unseen report --file report.json             # send a report
unseen report --summary "Fixed footer typo"  # quick report
unseen report --file report.json --dry-run   # preview
unseen ticket list                            # open tickets, latest QA / client comment
unseen ticket comment WEB-142 --role qa --body "Fails on iOS" --status qa_failed
unseen scan --dry-run                        # preview scan
unseen scan                                  # send scan suggestions
unseen verify --run "<tests with junit output>" --junit junit.xml   # CI
unseen --help
```

## Statuses at a glance

```
Implementation:  planned → in progress → implemented      (or blocked / not implemented)
Verification:    not checked → needs verification → verified   (or failed)
```

- Implemented + not checked ⇒ automatically **needs verification**
- Agent report touches a verified or failed feature ⇒ **needs verification**
- Any failing check in a trusted test run ⇒ **failed**
- All checks pass + implemented + clean commit ⇒ **verified**

## Server admin

```bash
php artisan unseen:create-user someone@example.com --name="Someone"
php artisan migrate
php artisan serve
php artisan test
```
