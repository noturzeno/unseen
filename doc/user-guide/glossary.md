# Glossary

| Term | Meaning |
|---|---|
| **Project** | One product or codebase tracked in Unseen. |
| **Owner / member** | The owner created the project and manages people and archiving. Members can view and edit. |
| **Feature** | One thing the product does, described in plain English. |
| **Category** | A group of features, e.g. *Billing*. |
| **Acceptance criteria** | Statements that define what "working" means for a feature. |
| **Implementation status** | Is it built? *Planned, In progress, Implemented, Blocked, Not implemented.* |
| **Verification status** | Has it been shown to work? *Not checked, Needs verification, Verified, Failed.* |
| **Source** | Where a claim came from: *Entered by a user, Reported by an agent, Detected in the repository, Confirmed by tests.* |
| **History** | The permanent log of status changes for a feature. |
| **Development report** | A record of work done: summary, features, files, tests, open items. A claim, not proof. |
| **Evidence** | Anything supporting a claim about a feature: reports, scan findings, test results. |
| **Unresolved item** | Work a report said was left open. Stays on the dashboard until someone closes it. |
| **Scan** | The CLI looking through a repository for signs of features. |
| **Suggestion** | A possible feature found by a scan, waiting for a person to accept, merge or dismiss it. |
| **Check** | A pattern linking a feature to the automated tests that prove it works. |
| **Test run** | A set of automated test results sent to Unseen (JUnit XML). |
| **Verification policy** | The rules deciding when a test run marks a feature verified or failed ([guide 8](08-verification.md)). |
| **Token** | A key that lets a tool (CLI, CI) act as you, limited to certain kinds of action. |
| **Agent token** | Token type for coding agents: read, report, scan. Can't edit or verify. |
| **CI token** | Token type for CI: read and submit test runs. |
| **`.unseen.json`** | File in a repository linking it to an Unseen project. No secrets; commit it. |
| **Progress** | Implemented %, and implemented-and-verified %, of all tracked features, rounded down. |
| **Verification debt** | The gap between the two progress bars: built but not yet shown to work. |
