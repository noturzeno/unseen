@if ($stage === 'join')
Connect this repository to Unseen. Unseen keeps the inventory of this project's features, and coding agents are its only writers. This project is already tracked: {!! $featuresCount !!} {!! Str::plural('feature', $featuresCount) !!} were recorded by another contributor, so do not scan, import or record the existing features again. Do all the steps below now, then tell me what you found.
@else
Set up Unseen for this repository. Unseen keeps the inventory of this project's features, and coding agents are its only writers. {!! $stage === 'new' ? 'This project has just started, so there is little or no code yet.' : 'This project is already developed, so its features need to be recorded from the existing code.' !!} Do all the steps below now, then tell me what you recorded.
@endif

1. Install the Unseen CLI (needs Node.js 22.18 or newer):

   npm install -g {!! $url !!}/downloads/unseen-cli.tgz

2. Link this repository to the Unseen project "{!! $name !!}". This saves the token in ~/.config/unseen, outside the repository. Never write the token into a file in the repository, a commit, a report, or your replies:

   printf '%s' '{!! $token !!}' | unseen init --api-url {!! $url !!} --project {!! $project->id !!} --token-stdin --force

@if ($stage === 'join')
3. If .unseen.json changed (or is new), commit it (it contains no secrets). If it was already committed unchanged, there is nothing to do.

4. Make sure CLAUDE.md (or AGENTS.md for other coding agents) has the section between the lines below. If an "## Unseen" section is already there, replace it with this one rather than adding a second copy:

-----
{!! $claudeMd !!}-----

5. Get to know the existing inventory instead of rebuilding it:
   - Run `unseen status --json` and read the features, categories, roadmap and open unresolved items that are already recorded.
   - Do not run a first-time import: don't record the existing features again, don't re-sync the roadmap and don't add diagrams that already exist.
   - Don't submit a report for this setup. From now on, follow the CLAUDE.md section above for the tasks I give you.
   - Tell me how many features, open unresolved items and roadmap phases you found, and the current phase.
@else
3. Commit .unseen.json (it contains no secrets).

4. Add the section between the lines below to CLAUDE.md (create the file if it doesn't exist; for other coding agents, use AGENTS.md). If an "## Unseen" section is already there, replace it rather than adding a second copy:

-----
{!! $claudeMd !!}-----

@endif
@if ($stage === 'new')
5. Start the inventory from the plan:
   - Look for planned features in the repository (README, docs, specs, issues or TODO files) and ask me for anything that is missing or unclear. Don't invent features.
   - Group them into a few categories, and record each as a feature with status `planned`, a plain-English description and acceptance criteria that say what "working" means.
   - Turn the plan into a roadmap of phases (e.g. MVP, then later releases), each with its implementation list, linking items to the features you recorded. If the repository has no plan file yet, create ROADMAP.md with the same phases and checklists. Sync it with `unseen roadmap sync`.
   - Add a diagram where it helps the plan (a flowchart of its steps, a use case diagram of who uses it, or a sequence diagram of how the parts talk).
   - From now on, follow the CLAUDE.md section above: move features to `in_progress` and `implemented` as you build them, tick roadmap items and re-sync the roadmap, link the tests that prove each one, and run `unseen scan` as code appears.
   - Finish with `unseen report` summarising what you recorded, and tell me the report URL.
@elseif ($stage === 'existing')
5. Build the inventory from the project's docs and code. Documented projects keep the truth in Markdown docs (often a docs/ folder): treat them as the main source, and check them against the code.
   - First run `unseen status --json`. If features are already recorded (another contributor set this project up), don't import them again: update the existing features, record only the ones that are missing, and skip diagrams and roadmap phases that are already there.
   - Start with the docs. Read them in this order and use each for what it is for:
     - Requirements / PRD / specs: the feature list (requirement ids such as BR-01 or F01 are the features).
     - Implementation matrix / status / progress reports: what is built, partial or blocked.
     - Implementation plan / roadmap / phases / milestones: the roadmap.
     - Next steps, QA reports, open tickets, fix lists: open work and known defects (they go in your report's `unresolved`, not as features).
     - Decision logs, design references, user guides: context and acceptance criteria.
     - Skip deployment and environment notes, `archive/` folders unless nothing newer covers a topic, and never open files that look private (`*.local.*`, secrets, credentials, `.env`). Never copy a secret, password, key or personal data into Unseen.
   - Run `unseen scan`. It reads the docs' requirement tables (id, requirement, status, section) and suggests one feature per requirement, with the doc's status and section as the defaults, plus what it finds in the code. Review every pending suggestion: accept real features (keep the requirement id in the name or description, improve the wording if needed), merge duplicates into the feature they belong to, and dismiss anything that isn't a feature.
   - Check the doc's status against the code before trusting it. A doc saying "Done" is a claim: record `implemented` only if the code is really there, and never mark anything verified.
   - Read the code (routes, pages, controllers, models, jobs, tests) for features the docs and scan missed, and record them too.
   - For every feature: a plain-English description, acceptance criteria, and an honest status: `implemented` only if it is fully built, `in_progress` if parts are missing (list the gaps in your report's `unresolved`), `not_implemented` if the code is there but unused.
   - Link the existing tests that prove each feature as checks, so CI test runs can verify them. Note features that have no tests.
   - Add diagrams for the main flows (a flowchart of the steps, a use case diagram of who uses it, or a sequence diagram of how the parts talk).
   - Build the roadmap from the plan docs: run `unseen roadmap draft > <file outside the repo>` (it turns "Phase N — …" sections and their tables and checklists into roadmap JSON and names the doc it used), then check it: tick items that are really built (use the implementation matrix and the code), link items to features, fix names, and drop anything that isn't part of the plan. Sync it with `unseen roadmap sync --file <file>`. If there is no plan, ask me whether to create one rather than inventing phases.
   - Work through large codebases one area at a time, and finish with `unseen report` summarising what you recorded (with features that have no tests in `unresolved`). Tell me the report URL.
@endif
