@php($id = $feature->id)
@php($label = fn ($v) => str_replace('_', ' ', $v instanceof \BackedEnum ? $v->value : (string) $v))
{!! match ($action) {
    'fix' => 'Fix the Unseen feature',
    'extend' => 'Change or extend the Unseen feature',
    'verify' => 'Get the Unseen feature',
    'unblock' => 'Unblock the Unseen feature',
    'revisit' => 'Revisit the Unseen feature',
    'continue' => 'Continue building the Unseen feature',
    default => 'Build the Unseen feature',
} !!} "{!! $name !!}" (feature #{!! $id !!} in project "{!! $project !!}"){!! $action === 'verify' ? ' verified' : '' !!}.
Current status: {!! $label($feature->status) !!}, verification: {!! $label($feature->verification_status) !!}{!! $feature->category ? ', category: '.$feature->category->name : '' !!}.
Unseen page: {!! $url !!}/features/{!! $id !!}

This repository should already be linked to Unseen (.unseen.json). If it isn't, stop and ask me for a setup prompt.

@if ($feature->description)
What it does:
{!! $feature->description !!}

@endif
@if ($feature->acceptanceCriteria->isNotEmpty())
Acceptance criteria (the feature is only done when all of these hold):
@foreach ($feature->acceptanceCriteria as $criterion)
- {!! $criterion->description !!}
@endforeach
@else
It has no acceptance criteria yet. Write them first (what "working" means, in plain English) and save them with `unseen api PATCH /features/{!! $id !!} --data '{"acceptance_criteria":["…","…"]}'`.
@endif

@if ($roadmap->isNotEmpty())
On the roadmap:
@foreach ($roadmap as $item)
- {!! $item->phase?->key !!} {!! $item->phase?->name !!}: "{!! $item->text !!}" ({!! $item->done ? 'ticked done' : 'not done' !!})
@endforeach

@endif
@if ($checks->isNotEmpty())
Tests linked to it (Unseen checks) and their latest results:
@foreach ($checks as $check)
@php($result = $check->latestResult)
- {!! $check->pattern !!}: {!! ! $result ? 'not run yet' : ($result->outcome === 'passed' ? 'passed' : ($result->outcome === 'failed' ? 'FAILED' : 'no matching tests in the last run')) !!}
@foreach (array_slice($result?->failures ?? [], 0, 5) as $failure)
  - {!! $failure['id'] ?? '' !!}{!! ! empty($failure['message']) ? ': '.\Illuminate\Support\Str::limit($failure['message'], 300) : '' !!}
@endforeach
@endforeach
@else
No tests are linked to it yet, so CI can't verify it.
@endif

What to do:
1. Run `unseen status --json` and read feature #{!! $id !!} and any open unresolved work related to it.
@switch ($action)
@case('fix')
@if ($checks->isEmpty())
2. It is marked failed but has no linked tests. Find out what doesn't work against the acceptance criteria (the Unseen page's timeline and the last report may say), and fix it.
@else
2. Fix the code so the failing checks above pass again. Work out whether the code or the test is wrong; don't weaken a test just to make it pass.
@endif
   If the feature is no longer fully built, set it back: `unseen api PATCH /features/{!! $id !!} --data '{"status":"in_progress","status_note":"…"}'`.
@break
@case('verify')
2. Don't change behaviour unless something doesn't meet the acceptance criteria. Make sure there are tests that prove every acceptance criterion; add the missing ones.
@break
@case('unblock')
2. Find out what blocks it (the Unseen page's timeline, the last report, open unresolved work, the code). If you can remove the blocker, do it and set the status to in_progress; if it needs someone else, stop and tell me exactly what is needed.
@break
@case('extend')
2. It is verified. Only change it if I've asked for a change; then keep every acceptance criterion working and update the criteria if the behaviour changes.
@break
@case('revisit')
2. It was marked not implemented. Check with me whether it is back in scope before building anything.
@break
@default
2. Set it in progress if it isn't: `unseen api PATCH /features/{!! $id !!} --data '{"status":"in_progress"}'`. Then build what is missing so every acceptance criterion holds, following the project's existing patterns.
@endswitch
3. Write or update tests that prove each acceptance criterion, run them, and link any new ones as checks: `unseen api POST /features/{!! $id !!}/checks --data '{"pattern":"<test class, file or name pattern>"}'` (skip ones already linked above).
4. Only when it is fully built: `unseen api PATCH /features/{!! $id !!} --data '{"status":"implemented","status_note":"<what changed>"}'`. Never mark it verified: only CI test runs (`unseen verify`) verify.
5. If it has a roadmap item, tick it when it is fully built and re-sync the whole roadmap (`unseen roadmap show`, then `unseen roadmap sync --file <file>`).
6. If it helps explain the feature, add or update a diagram (flowchart, use case or sequence): `unseen api POST /features/{!! $id !!}/diagrams --file <json outside the repo>`.
7. Finish with `unseen report`: this feature with its status, the tests you actually ran and their real results, and anything left in `unresolved`. Tell me the report URL.
Never put secrets, `.env` values or personal data into Unseen.
