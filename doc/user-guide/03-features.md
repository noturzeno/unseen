# 3. Features and categories

The **feature inventory** is the heart of Unseen: a plain-English list of what the product does, with an
honest status for each item.

## Two statuses, never mixed

Every feature has **two separate statuses**:

| Implementation: *is it built?* | Meaning |
|---|---|
| Planned | Wanted, not started |
| In progress | Someone (or an agent) is working on it |
| Implemented | Marked as built |
| Blocked | Can't continue (waiting on something) |
| Not implemented | Checked and found missing, or decided against |

| Verification: *has it been shown to work?* | Meaning |
|---|---|
| Not checked | Nobody has looked yet |
| Needs verification | Built (or changed) and waiting for a test run to confirm it works |
| Verified | Confirmed working by a trusted automated test run |
| Failed | Checked and found not working |

A feature can be **Implemented** and still **Needs verification**. That is normal and honest.

## Where features come from

**Coding agents record everything through the API**: features, categories, acceptance criteria, statuses, linked
tests and diagrams. The web app shows that data; it has no forms to change it. People manage only projects,
members and API tokens (see [Projects and team](02-projects-and-team.md) and
[Tokens and security](09-tokens-and-security.md)).

| Data | Written by | Shown as |
|---|---|---|
| Features, categories, criteria, statuses, diagrams | Coding agent (agent token) | *Reported by an agent* |
| Verified / failed | Trusted test run (CI or `unseen verify` with a verify token) only | *Confirmed by tests* |

**Automatic rules Unseen applies to every status change**

| When an agent does this | Unseen also does this | Why |
|---|---|---|
| Sets a *not checked* feature to **Implemented** | Verification → **Needs verification** | Built but unchecked work stays visible |
| Changes the implementation status of a **Verified** feature | Verification → **Needs verification** | The old check no longer applies |
| Tries to mark a feature **Verified** or **Failed** | Refused with an error | Only trusted test runs verify |

**What good feature data looks like** (useful when reviewing what your agents record)
- Names and descriptions a non-developer (a client or a project manager) understands.
- Acceptance criteria that say what "working" means. They make verification meaningful.
- One feature is one thing a user can do. "User management" is too big; "Invite a team member" is right.

## Hand a feature to your coding agent

Every feature page starts with **Next step for your coding agent**, for example *Continue building it*, *Get it
verified* or *Fix the failing checks*. Click **Copy prompt** (or **Preview** to read it first) and paste it into
Claude Code in the project's repository. The prompt holds the feature's id, status, description, acceptance
criteria, linked tests with their latest results and failure messages, and its roadmap item, plus what to do and how
to record it in Unseen. It contains no token: the repository is already linked.

## See where a feature is

The top of a feature's page shows its **lifecycle**: *Planned → In progress → Implemented → Verified*, with the
date each step was reached. The current step is highlighted, and problems show on the step they affect:
*Blocked* (orange), *Waiting for verification* (blue), *Verification failed* (red).

## Diagrams

A feature can have any number of diagrams that show how it works:

| Type | Use it for |
|---|---|
| **Flowchart** | The steps and decisions in the feature |
| **Use case** | Who uses the feature and what they can do |
| **Sequence** | How the parts (user, web app, API, database, other services) talk to each other |

Coding agents add them through the API, written in [Mermaid](https://mermaid.js.org/intro/syntax-reference.html).
On the feature page, open **Diagrams** and click a diagram's tab to view it, **Full screen** to fill the screen
with it (Esc or **Exit full screen** to close), or **Source** to see its text. In full screen, zoom with **−** / **+**,
the `-` / `+` keys, Ctrl/⌘ + scroll or a trackpad pinch; drag to move around; click the percentage (or press `0`) to fit.
Diagrams describe the feature; they are not proof that it works.

### Keep diagrams in sync with the code

Unseen can't read your code, so the coding agent checks the diagrams for you. On the **Diagrams** card:

- **Sync with code** (**Draw from code** when there are none yet) works like a [Proceed button](05-claude-code-cli.md#one-click-proceed-buttons):
  it opens Claude Code in the project folder with a prompt to compare each diagram with the current code.
- **Copy sync prompt** copies the same prompt to paste into Claude Code yourself.

The agent updates diagrams that no longer match, removes ones whose behaviour is gone, adds missing ones, and
changes nothing else. It finishes with a report naming the commit it checked and, for each diagram, whether it was
already up to date, updated, removed or added. That report in the **Timeline** confirms the diagrams are current,
even when none needed changing.

## See a feature's history

Open the feature and look at the **Timeline**. It lists status changes and agent reports that mention the
feature, newest first. Every status change shows:
- what changed (e.g. *Implementation in progress → implemented*)
- **where the claim came from**: *Entered by a user*, *Reported by an agent*, *Detected in the repository*, or *Confirmed by tests*
- who made it, when, and the note

> Features can't be deleted, so their history is always kept. A feature recorded by mistake is set to
> **Not implemented** with a note.

## Find features

On the project overview, the **Features** table has quick status filters (with counts), a **search** box, and
filters for **verification** and **category** (including *Uncategorised*). Click **Clear filters** to reset.
Click a row to open the feature.

## What's next

→ [4. Reading the dashboard](04-dashboard.md)
