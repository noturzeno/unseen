# 13. PRD vs design: deciding mismatches

Designers sometimes design something different from what the PRD says, and the build usually follows the design.
Unseen lists every such difference so you can decide each one on purpose.

It's on for every project by default. To turn it off (for a project with no separate design), untick **Track PRD vs
design** in the project's **Settings**. That hides the tab and the dashboard card and drops it from the agent's
instructions; mismatches already recorded are kept and come back when you turn it on again.

## Getting the list

On the project's **PRD vs design** tab, use **Copy prompt** (or **Proceed**) on *Find PRD vs design mismatches*.
Your coding agent compares the PRD, the design and the code and records each difference. It changes nothing and
never decides. Run it again any time; known differences are updated, not duplicated.

## Reading a mismatch

Each card shows:
- **PRD says** and **Design says**, side by side. The side that was built has a *Built* badge.
- **Built to the design / the PRD / neither / not built yet**, with what the code does now.
- **If we change it:** the impact of switching.
- Who should decide: **Your decision needed** (red) or **Client decision needed** (orange).

The tiles at the top filter by *Your decision*, *Client decision*, *Decided, to apply* and *Applied*. The project
overview shows the same counts near the top, and each feature page lists its own mismatches.

## Deciding

Choose **Follow the PRD**, **Keep the design**, or **Something else** (write what you want in the note; the note is
optional for the first two). For client decisions, ask the client and record their answer the same way. You can
**Change decision** until your agent has applied it.

## Applying

Decided mismatches become a high-priority suggested next step: **Apply N PRD vs design decisions**. Your agent
changes the code to match the PRD, or updates the PRD to describe the design, and marks each one **Applied**.
