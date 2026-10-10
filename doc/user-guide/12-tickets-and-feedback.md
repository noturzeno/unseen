# 12. Tickets, QA and client feedback

Unseen follows each ticket from the client's request to sign-off, and keeps what QA and the client said about it.
Like everything else in Unseen, **your coding agent records it**: you give the agent the ticket and pass on the
feedback, and the dashboard shows it.

## The flow

```
Open → In progress → Ready for QA → Client review → Done
                         └→ QA failed     └→ Changes requested     (back to development)
Closed = dropped without finishing
```

| Status | Means | Who moves it there |
|---|---|---|
| Open | Recorded, not started | The agent, when you give it the ticket |
| In progress | Being built | The agent, when it starts |
| Ready for QA | Built, waiting for QA | The agent, when its work is ready (usually in its report) |
| QA failed | QA sent it back | The agent, recording QA's comments you passed on |
| Client review | QA passed; with the client | The agent, recording QA's pass |
| Changes requested | The client wants changes | The agent, recording the client's comments |
| Done | The client signed it off | The agent, recording the client's approval |

The agent never marks its own work beyond **Ready for QA**. "Done" is the client's sign-off, not verification:
features are still only verified by test runs ([guide 8](08-verification.md)).

## Giving work and feedback to the agent

Just tell Claude Code, for example:

- *"New ticket WEB-142 from the client: the Pay button does nothing on Safari. High priority, it's the Checkout feature."*
- *"QA says WEB-142 still fails on iOS 17."*
- *"The client approved WEB-142."*

The agent runs `unseen ticket add`, `unseen ticket comment --role qa …` or `--role client …` and records the right
status. Each comment keeps **whose words they are** (QA, Client, Developer, Project manager, Coding agent).

## Where it shows

- **Overview:** *QA & client feedback* (latest comments) and *Tickets* (how many are sent back, with QA, with the
  client, done, and the open tickets with their latest feedback).
- **Tickets tab:** tickets per stage, filters (open, done or closed, all, exact status, type) and recent feedback.
- **Ticket page:** the flow tracker, details, linked feature, and the whole thread with linked agent reports.
- **Feature page:** the tickets linked to that feature.
- **Report page:** the tickets that report moved.

## Personal data

Comments should say what was found, not who found it. Tell the agent the role ("the client", "QA"), not names,
emails or phone numbers. The CLI masks emails, phone numbers and HKID numbers before sending as a safety net.
Hong Kong PDPO applies to anything stored ([guide 9](09-tokens-and-security.md)).
