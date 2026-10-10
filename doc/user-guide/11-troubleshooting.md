# 11. Troubleshooting and FAQ

## Signing in

| You see | Do this |
|---|---|
| *The provided credentials are incorrect.* | Check the email and password. An admin can reset the password ([guide 10](10-administration.md)). |
| *Too many sign-in attempts. Try again in N seconds.* | Wait, then try again. |
| A **403** error page when opening a project | You're not the owner or a member. Ask the owner to add you (Settings → People). |

## CLI messages

| Message | Cause and fix |
|---|---|
| `This repository is not linked to Unseen. Run "unseen init" first.` | No `.unseen.json` in this folder or above it. Run `unseen init` in the repository root. |
| `No Unseen token for <url>. Run "unseen init" or set UNSEEN_TOKEN.` | You're on a new computer, or the credentials file was removed. Run `unseen init --force`. |
| `Not authenticated. Run "unseen init" or set UNSEEN_TOKEN.` | The token expired (after 90 days for CLI tokens) or was revoked. Run `unseen init --force`. |
| `Not allowed. The token or user does not have access to this.` | You're not a member of that project, or the token type doesn't allow this action ([guide 9](09-tokens-and-security.md)). |
| `This token cannot submit test results…` | `verify` needs a **CI test results** token, not the agent token. |
| `Refusing to send credentials over plain http to <host>. Use https://.` | Use the `https://` address of your Unseen server. |
| `Could not reach Unseen at <url>` | Is the server running (`php artisan serve` locally)? Is the URL right? Are you on the VPN? |
| `.unseen.json already exists here. Use --force to relink.` | To change project or server: `unseen init --force`. |
| `features[0]: no feature named "X"` | Use the exact name or `#id` from `unseen status`, or add the feature in Unseen first. |
| `features[0]: "X" matches several features (ids 3, 4)` | Use the id instead of the name. |
| `Unknown report field(s): verification_status. Reports cannot set verification…` | Remove the field. Verification comes from people or test runs. |
| `Masked what looked like secrets in: $.tests[0].command` | Not an error. Check the report doesn't need that value, and don't put secrets in reports. |
| `Warning: …credentials.json is readable by other users` | Run the `chmod 600 …` command shown. |
| `Stopped after 20,000 files…` (scan) | Very large repository; the rest wasn't scanned. Contact the developer if this matters. |
| `JUnit report not found: …` | The test command didn't write the file. Check the reporter flags ([guide 8](08-verification.md)). |

Exit codes: `0` = OK, `1` = error, `2` = wrong command usage. `verify --run` returns your test command's exit code.

## Verification questions

**All tests passed but the feature isn't Verified. Why?**
Open the test run page (link printed by `unseen verify`). The feature shows one of:
- *Passed, not applied: The feature is not marked implemented.* → set it to Implemented first.
- *…The run included uncommitted changes.* → commit everything (ignored files don't count), then run again.
- *…The run is not tied to a git commit.* → run inside a git repository, without `--no-git`.
- *Incomplete* → one of its checks matched no tests. Fix the pattern or run the full suite.
- *Not covered* → none of its checks ran in this run.

**A verified feature went back to "Needs verification". Why?**
A development report listed it (the code changed since it was checked), or someone changed its implementation status.
The next passing test run, or a person, verifies it again.

**A check says "No matching tests".**
The pattern doesn't match any test name in the JUnit file. Run `unseen verify --junit junit.xml --dry-run --json`
to see the exact test ids (e.g. `Tests\Feature\InvoiceTest::test_list` for PHPUnit), then adjust the pattern.

## Scan questions

**The scan suggests things that aren't features.** Dismiss them. They won't come back.

**A real feature is missing from the scan.** The scan only spots common patterns. Ask the coding agent to record the feature.

**A suggestion appears twice under different names.** Merge both into the same feature.

## Other questions

**Can I delete a feature, report or project?** No. History and evidence are kept on purpose. Set a feature to
*Not implemented*, or archive a project.

**Can I use agents other than Claude Code?** Yes. Any tool that can run a command can use `unseen report`,
`scan` and `status`. Adapt the CLAUDE.md instructions to that tool's instruction file.

**Do I need PostgreSQL?** For a shared server, yes. For a quick trial on your laptop, SQLite works.

**Where do I report a bug in Unseen itself?** Tell the Unseen maintainers on your team. For security problems
(leaked tokens, exposed data), email **it@visibleone.com**.
