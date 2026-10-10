@switch ($kind)
@case('suggestions')
Review the {!! $pending !!} pending scan suggestions in the Unseen project "{!! $project !!}" (project #{!! $projectId !!}): features `unseen scan` found in the code or docs that aren't recorded yet.
Suggestions: {!! $url !!}/projects/{!! $projectId !!}/suggestions
@break
@case('ci')
Set up CI verification for the Unseen project "{!! $project !!}" (project #{!! $projectId !!}). Tests are linked to features as checks, but no test run has ever been sent, so nothing can be verified.
@break
@case('tests')
Link tests to the implemented features in the Unseen project "{!! $project !!}" (project #{!! $projectId !!}) that have none, so CI can verify them:
@foreach ($features->take(60) as $f)
- #{!! $f->id !!} {!! str_replace(["\r", "\n"], ' ', $f->name) !!}
@endforeach
@if ($features->count() > 60)
- …and {!! $features->count() - 60 !!} more (see `unseen status --json`).
@endif
@break
@endswitch

This repository should already be linked to Unseen (.unseen.json). If it isn't, stop and ask me for a setup prompt.

What to do:
1. Run `unseen status --json` and read the features involved.
@switch ($kind)
@case('suggestions')
2. Get the pending suggestions: `unseen api GET /projects/{!! $projectId !!}/suggestions`. Each one has evidence (files, routes, doc rows) and, from docs, a suggested category and status.
3. For each: if it is a real feature nobody tracks yet, accept it (`unseen api POST /suggestions/<id>/accept`, optionally with a better `name`/`description`; check a doc's status against the code first, and pass `"status"` if the code says otherwise). If it duplicates a tracked feature, merge it (`/merge` with `{"feature_id":…}`). If it isn't a feature (helper code, plumbing, a task), dismiss it (`/dismiss`).
4. Add acceptance criteria to the features you accepted (`unseen api PATCH /features/<id> --data '{"acceptance_criteria":["…"]}'`) and link the tests that already prove them as checks.
@break
@case('ci')
2. Make the project's test runs produce JUnit XML (e.g. `php artisan test --log-junit junit.xml`, Vitest `--reporter=junit`, Jest with jest-junit, Playwright `--reporter=junit`, pytest `--junitxml`). Make sure the test names in the XML match the patterns linked as checks; adjust either side if they don't.
3. Add a CI step (or a documented local command) that runs the tests and sends the results: `unseen verify --run "<test command>" --junit <report.xml>`. It needs a token with the `verify` ability (a "CI test results" token from Unseen's API tokens page), stored as a CI secret named UNSEEN_TOKEN, never in the repository. Ask me to create that token; don't use the agent token.
4. Run it once locally if I give you a verify token, or tell me exactly what to add to CI.
@break
@case('tests')
2. For each feature, find the existing tests that prove its acceptance criteria and link them: `unseen api POST /features/<id>/checks --data '{"pattern":"<test class, file or name pattern>"}'`. Use specific patterns, never `*`.
3. Where a feature has no such tests, write them (start with the most important features), run them, and link them.
4. If a test shows a feature is broken, fix it or set it back to in_progress with a note.
@break
@endswitch
5. Never mark anything verified: only CI test runs (`unseen verify`) verify.
6. Finish with `unseen report`: what you changed, the tests you actually ran and their real results, and anything left in `unresolved`. If it is too much for one session, do the most important part first and tell me what is left. Tell me the report URL.
Never put secrets, `.env` values or personal data into Unseen.
