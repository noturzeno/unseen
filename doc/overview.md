# Unseen Implementation Guide for AI Coding Agent

## 1. Product goal

Build **Unseen**, a web application that helps developers understand what AI coding agents have implemented across their software projects.

The primary user problem:

- Projects are built with AI coding agents, but the developer loses track of which features exist.
- The developer may not fully understand the codebase or remember what the agent has already built.
- They need a clear dashboard showing the project's feature inventory, implementation status, verification status, and outstanding work.

Unseen is more than a task board. Its core purpose is to maintain a useful, evidence-based inventory of implemented features.

## 2. Initial scope

Build the first version for personal use and a small team. Keep the architecture extensible for a future public SaaS product.

Prioritize **Claude Code first**. Keep the integration design open so other coding agents can be added later.

### MVP features

1. Project management
  - Create, view, edit, and archive projects.
  - Store project name, description, repository location/URL, primary technology stack, and timestamps.
  - Show all projects on a dashboard.
2. Feature inventory
  - Organize features by category or module.
  - Each feature should have a name, plain-English description, expected behavior/acceptance criteria, implementation status, verification status, evidence, and last-updated timestamp.
  - Support manual creation and editing.
  - Support feature statuses such as:
    - `planned`
    - `in_progress`
    - `implemented`
    - `blocked`
    - `not_implemented`
    - `ignored` (a person decided it won't be built; only people set it)
  - Support verification statuses such as:
    - `not_checked`
    - `needs_verification`
    - `verified`
    - `failed`
  - Do not conflate implementation status with verification status.
3. Project dashboard
  - Show project summary and feature counts by status.
  - Show recently updated features and items requiring attention.
  - Show progress calculated from tracked features and clearly document the calculation.
  - Do not present AI estimates as verified facts.
4. Development activity / evidence
  - Record reports from the coding agent, relevant changed files, Git commit or diff references where available, test commands, test outcomes, and timestamps.
  - Keep an audit/history trail of status changes and agent reports.
  - Store the source of each claim: user-entered, agent-reported, repository-detected, or test-verified.
5. Repository onboarding
  - Provide a way to associate a project with a repository.
  - For the MVP, allow a developer to trigger a codebase inventory scan.
  - The scan should suggest candidate features based on evidence such as routes, controllers, models, UI pages/components, API endpoints, tests, and documentation.
  - Mark discovered items as suggestions requiring review; do not assume that the existence of code proves a working feature.
  - Let the user accept, edit, merge, or dismiss suggestions.
6. Claude Code integration
  - Provide a small NPM package/CLI for a repository to communicate with Unseen.
  - Initial intended workflow:
  1. Initialize or link a repository to an Unseen project.
  2. Retrieve project context, requirements, and relevant features.
  3. Allow Claude Code to report work performed.
  4. Submit a structured implementation report including summary, features affected, files changed, tests run/results, unresolved work, and confidence/limitations.
    ep API credentials out of source control. Use environment variables or a secure local configuration file with appropriate permissions.
     not automatically mark a feature `verified` just because the agent reports it complete.
     full CLI integration is too large for the first iteration, first implement the API and a documented mock/manual report flow, then add CLI commands incrementally.



## 3. Suggested architecture

Use the following as a default, unless the existing repository already has established conventions:

- Backend API: Laravel
- Database: PostgreSQL
- Web frontend: choose the framework already present in the repository; if none exists, ask before introducing a new frontend stack or use the simplest well-supported Laravel-compatible approach.
- Agent integration: TypeScript-based NPM CLI/package
- API communication: versioned REST API, for example `/api/v1/...`

Do not introduce microservices, queues, vector databases, or complex AI orchestration in the MVP unless a concrete requirement justifies them.

### Conceptual flow

Developer / Claude Code
        |
        v
Unseen CLI / integration
        |
        v
Laravel API ---- PostgreSQL
        |
        v
Unseen web dashboard

Repository scanning and test results supply evidence to the feature inventory. The dashboard distinguishes reported implementation from verified behavior.

## 4. Suggested data model

Design migrations and models for these core entities. Adjust naming to project conventions.

- `users`
- `projects`
- `project_repositories` (optional in MVP if one repository per project is enough)
- `feature_categories`
- `features`
- `feature_acceptance_criteria` (or structured JSON if simpler and justified)
- `development_reports`
- `feature_evidence`
- `feature_status_history`
- `repository_scans` (can be deferred until scanning is implemented)

A feature should be linked to a project and optionally a category. Store status fields with validation/enums or well-defined constants. Prefer relational columns for important queryable fields; use JSON for flexible report payloads, not as a substitute for the entire relational schema.

Consider basic authorization from the start: users can access only projects they own or are explicitly allowed to access. For a private MVP, keep roles simple.

## 5. Initial API capabilities

Design versioned endpoints as appropriate for the Laravel conventions, including equivalents of:

- `GET /api/v1/projects`
- `POST /api/v1/projects`
- `GET /api/v1/projects/{project}`
- `PATCH /api/v1/projects/{project}`
- `GET /api/v1/projects/{project}/features`
- `POST /api/v1/projects/{project}/features`
- `PATCH /api/v1/features/{feature}`
- `GET /api/v1/projects/{project}/reports`
- `POST /api/v1/projects/{project}/reports`
- `GET /api/v1/projects/{project}/dashboard`

Protect authenticated endpoints. Validate request payloads and ensure project-level authorization. Exact routes may change to match repository conventions.

## 6. MVP user experience



### Projects page

- List projects with name, short description, feature progress summary, and last activity.
- Allow project creation and editing.



### Project overview

- Summary counts for total features, implemented, in progress, blocked, and needing verification.
- Feature list filterable by status/category.
- Recent agent reports and recent changes.
- Clear distinction between implementation and verification.



### Feature detail

- Name and plain-English description.
- Acceptance criteria / expected behavior.
- Implementation status and verification status.
- Evidence and history.
- Related report, changed files, and test results where available.



### Review discovered features

- Display scan results as suggestions.
- Let a human accept/edit/reject each suggestion.
- Avoid creating duplicate features when rescanning.



## 7. Important product rules

1. A code symbol, page, route, or database model is evidence of possible implementation, not proof that a complete feature works.
2. Agent-reported completion is not the same as test-verified completion.
3. Every automated conclusion should include its source and, where possible, supporting evidence.
4. Keep uncertainty visible. Use `needs_verification` instead of pretending to know.
5. Do not silently overwrite user-confirmed requirements with AI-generated guesses.
6. Prefer readable, plain-English explanations over code-heavy dashboards.
7. Keep the first version small and working; do not build every planned feature at once.

### Decision (2026-10-09): agents write, people view

This supersedes the "manual creation and editing" and "let the user accept/edit/reject suggestions" items above.

- **Coding agents are the only writers of tracking data.** Features, categories, acceptance criteria, statuses,
  checks, diagrams, reports and suggestion reviews come in through the API with the coding-agent token
  (`read`, `report`, `record`), recorded as `agent_reported`. That token cannot change projects, members or tokens.
- **The web app is view only** for that data. People use it to watch progress and to manage projects, members
  and API tokens.
- **Only trusted test runs verify.** `verified` and `failed` come only from test runs (`verify` ability); the
  feature endpoints refuse them. Rules 2 and 4 above still hold: an agent's "done" is `needs_verification`.



## 8. Implementation sequence

Work in small, testable milestones. Inspect the existing repository before changing files. Do not assume a blank repository.

### Milestone 1 â€” Foundation

- Inspect the repository and report its existing framework, dependencies, conventions, and tests.
- Propose a concise implementation plan.
- Implement project CRUD, database migrations/models, authentication/authorization appropriate to the existing app, and basic API tests.
- Do not replace existing architecture without explaining why.



### Milestone 2 â€” Feature inventory

- Implement feature/category CRUD and status validation.
- Add acceptance criteria and feature history.
- Add tests for authorization and status transitions.



### Milestone 3 â€” Dashboard

- Implement project summary and feature status counts.
- Add feature filtering and recent activity.
- Ensure progress calculations are documented and consistent.



### Milestone 4 â€” Agent reports

- Implement a structured development report endpoint and UI.
- Store affected features, changed files, test results, unresolved work, and report source.
- Add tests for payload validation and authorization.



### Milestone 5 â€” Claude Code CLI

- Create a TypeScript NPM package/CLI in an appropriate package directory or separate repository, based on the existing setup.
- Start with a small command set, for example:
  - `unseen init`
  - `unseen status`
  - `unseen report`
- Add commands only when their behavior is clearly specified.
- Document setup, authentication, configuration, and example usage.
- Never commit secrets.



### Milestone 6 â€” Repository scan

- Add a scan that generates candidate feature suggestions with evidence.
- Require user review before adding suggestions as confirmed requirements/features.
- Make scans repeatable and avoid duplicates.



### Milestone 7 â€” Verification

- Connect available automated test results and repository evidence to feature verification.
- Mark features verified only when the verification policy and evidence support it.
- Show failed or missing checks clearly.

Do not attempt all milestones in one giant change. Complete and test one milestone at a time.

## 9. Definition of done for the initial MVP

The initial MVP is useful when a developer can:

1. Create a project.
2. Add and organize its features.
3. View a dashboard showing the project's feature inventory and status.
4. Distinguish reported implementation from verified behavior.
5. Record an AI coding agent's work as a structured report.
6. Review recent changes and unresolved items.
7. Use the design as a foundation for a Claude Code CLI integration.



## 10. Instructions to the coding agent

Start by inspecting the current repository and its instructions (for example, `AGENTS.md`, `CLAUDE.md`, README, package manifests, Laravel conventions, and tests).

Then:

1. Summarize the existing project state.
2. Identify missing information and ask only essential questions.
3. Propose a milestone-based plan that matches the existing codebase.
4. Implement Milestone 1 only unless the user explicitly requests more.
5. Run relevant tests and report exactly what passed, failed, or could not be run.
6. List changed files and any required setup commands.
7. Do not claim features are complete or verified without evidence.
8. Keep changes focused, maintainable, and easy for the developer to review.

The goal is a reliable, understandable feature-tracking system for AI-assisted software developmentâ€”not a complicated project-management suite.