# 4. Reading the dashboard

## The Projects page

Each project card shows:
- **Two progress bars:** *Implemented* and *Implemented and verified* (explained below)
- **"N need attention"** if any features are blocked, failing, or waiting for verification
- **Last activity:** the latest change to the project, its features, or a new report

## The project overview, top to bottom

The dashboard is **view only**: coding agents write the data through the API.

### Project tabs
| Tab | Shows |
|---|---|
| Overview | This page |
| Roadmap | The project's phases and implementation lists, synced by the coding agent |
| Tickets (N) | Tickets and where each is in the flow, with QA and client comments ([guide 12](12-tickets-and-feedback.md)) |
| Reports | Development reports ([guide 6](06-development-reports.md)) |
| Suggestions (N) | Scan suggestions waiting for an agent's review ([guide 7](07-repository-scan.md)) |
| Unresolved work (N) | Open and closed items from reports |
| Settings | Project details, people, archive ([guide 2](02-projects-and-team.md)) |

### Suggested next steps
The top card ranks what to do next, so you can pick by priority and hand it to your coding agent with **Copy prompt**:

| Priority | Suggestion |
|---|---|
| High | Fix a feature whose verification **failed** · resolve an open item that looks like a **defect or security/privacy problem** · continue an **overdue** roadmap phase |
| Medium | **Unblock** a blocked feature · review **scan suggestions** · **set up CI verification** (tests linked, no test run yet) · **link tests** to implemented features without any · continue the **current roadmap phase** |
| Low | Continue features **in progress** (oldest first) · work through the remaining **open items** |

The top five show first; **Show N more** lists the rest. The card only suggests: nothing changes until the agent
acts on a prompt. It disappears when there is nothing to suggest.

### Features
The second card: the feature table with quick status filters, search, and verification and category filters.
Click a row to open the feature.

### Counters
Tracked features · Implemented · In progress · Blocked · Need verification · Failed checks.

### Progress: how it's calculated

```
Implemented %              = features with status "implemented"               ÷ all tracked features
Implemented and verified % = features "implemented" AND verification "verified" ÷ all tracked features
```

- **Every** tracked feature counts, whatever its status.
- Percentages are **rounded down**, so 199 of 200 shows 99%. You never see 100% until it's really 100%.
- With no features, progress shows as **unknown**, not 0% or 100%.
- Nothing is estimated. Only statuses that someone or something actually set are counted.

The gap between the two bars is your **verification debt**: work that is claimed as built but not yet shown to work.

### Needs attention
Features that are **failed** (shown first), then **blocked**, then **needs verification**. Click one to deal with it.

### Recent changes
The latest status changes, with their source (user, agent, repository, tests).

### Recent agent reports and unresolved work
- **Recent agent reports:** the latest reports, with failing-test and unresolved badges.
- **Unresolved work (N):** open items that reports said were left to do. Agents close them in a later report.
  Items that need **your decision** (red) or **the client's** (orange) are badged and listed first, with what is
  needed. On the Unresolved work page, the four tiles at the top (*Waiting on you*, *Waiting on the client*,
  *Not sorted yet*, *Agent can do*) filter the list. Client items have **Copy message for client**. Items needing
  a decision have **Copy prompt with decision**: fill in the blank before pasting it to your agent. If items aren't
  sorted yet, **Copy prompt** / **Proceed** on the "not sorted" card asks your agent to sort and split them.
  **View all** shows open and closed items and who closed each.

### Verification
- The **latest automated test run** and its pass/fail counts.
- **Implemented features without automated checks.** Test runs can't verify these until an agent links tests
  to them ([guide 8](08-verification.md)).

### Roadmap
The project's own plan, synced by its coding agent from the repository (ROADMAP.md, milestones…). The overview
card lists each phase with its progress; the **Roadmap** tab shows the full timeline:
- each phase's status (*Planned*, *In progress*, *Done*, *Blocked*), target date (*Overdue* in red when past and
  not done), and how many of its items are done
- its implementation list, with each item's linked feature and that feature's verification status
- **Done, not all verified** when the agent marked a phase done but some linked features aren't verified yet

Each phase has a **Copy prompt** button, and the top of the Roadmap tab offers the current phase (the first that
isn't done). The prompt asks your coding agent to *start* or *continue* the phase (work through its open items in
order), *unblock* it, or *close it out* (check every ticked item is really built and get its features tested so CI
can verify them). It lists the open items with their features' status and verification, and the items ticked done
whose features aren't verified. It contains no token.

### Unresolved work tab
Open items come with prompts for your coding agent: **Copy prompt** on an item asks it to resolve that one, and
**Work through all N open items** at the top asks it to triage and fix them in order (defects and security first).
Items that need a decision, access or a review are handed back to you rather than guessed. The agent closes what it
fixed through its report (`resolves`), so the **Done** tab shows who closed what. The prompts contain no token.

### Charts
**Click a chart to see its features.** The Features list jumps into view, filtered, with a *Showing: …* line and
**Clear filters**:
- a category's name or bar in **By category**: that category's features; a coloured part of its bar: its
  *verified*, *implemented but not verified*, or *not implemented yet* features
- a part or legend entry of **Feature status**: features with that implementation or verification status

- **Feature status:** how features split across implementation and verification states.
- **Activity:** feature status changes per week, last 12 weeks (**Show as table** lists the numbers).
- **By category** (in Progress): verified, implemented but not verified, and not implemented, per category.

## A weekly routine (10 minutes)

1. Open each active project.
2. **Needs attention:** fix or re-check failed items, unblock blocked ones.
3. **Suggestions:** review anything new from scans.
4. **Unresolved work:** close what's done.
5. Look at the gap between the two progress bars. If it's growing, schedule verification time.

## What's next

→ [5. Connecting Claude Code](05-claude-code-cli.md)
