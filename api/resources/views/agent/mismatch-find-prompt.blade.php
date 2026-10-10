Find where the PRD and the designer's design disagree in the Unseen project "{!! $project !!}" (project #{!! $projectId !!}), and record each mismatch so I can decide it on the dashboard. Don't change any code in this task.

This repository should already be linked to Unseen (.unseen.json). If it isn't, stop and ask me for a setup prompt.

1. Find the sources:
   - The PRD: requirement docs in the repository (PRD, spec, requirements, implementation matrix). Requirement ids such as BR-01 match feature names in `unseen status --json`.
   - The design: design files, exported screens or design notes in the repository, Figma links in the docs, or what the code was built from. If you can't find the design, stop and ask me where it is; don't guess it from the code.
2. Compare them feature by feature, and check the code to see which one was built. Only record real conflicts: different behaviour, fields, steps, rules, wording that changes meaning, or something one has and the other doesn't. Skip pure styling (colours, spacing) unless the PRD specifies it.
3. Record each mismatch (write the JSON to a file outside the repository):
   `unseen mismatch add --file /tmp/mismatches.json`
   with a list of
   `{"key":"PRM-01-promo-list","reference":"PRD §4.11 PRM-01 · design: Checkout screen","feature":"<feature name or id>","title":"Promo codes: list vs. text box","prd_says":"…","design_says":"…","built_to":"design|prd|neither|not_built","built_note":"what the code does now","impact":"what changes for users or the client if we switch","decide_by":"team|client"}`
   - Keep `key` stable (requirement id + short slug): recording the same key again updates it instead of adding a duplicate.
   - Quote or closely paraphrase the PRD and the design; keep each side short and concrete.
   - `decide_by` is `client` when it changes what the client asked for or signed off (scope, business rules, content, legal), otherwise `team`.
4. Never decide a mismatch yourself and never change code or docs to pick a side: decisions are mine (or the client's), made on the dashboard.
5. Finish by telling me how many mismatches you recorded, grouped by who decides, and the page: {!! $url !!}/projects/{!! $projectId !!}/mismatches

Never put secrets, `.env` values or personal data into Unseen.
