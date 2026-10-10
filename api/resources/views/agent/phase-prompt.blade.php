@php($label = fn ($v) => str_replace('_', ' ', $v instanceof \BackedEnum ? $v->value : (string) $v))
@php($featureLine = fn ($f) => $f ? " (feature #{$f->id} \"".str_replace('"', "'", $f->name)."\": {$label($f->status)}, {$label($f->verification_status)})" : ' (no feature linked)')
{!! match ($action) {
    'unblock' => 'Unblock the roadmap phase',
    'close' => 'Close out the roadmap phase',
    'continue' => 'Continue the roadmap phase',
    default => 'Start the roadmap phase',
} !!} {!! $phase->key !!} "{!! $name !!}" in the Unseen project "{!! $project !!}" (project #{!! $phase->project_id !!}).
Status: {!! $statusLine !!}.
Roadmap: {!! $url !!}/projects/{!! $phase->project_id !!}/roadmap

This repository should already be linked to Unseen (.unseen.json). If it isn't, stop and ask me for a setup prompt.

@if ($phase->description)
Goal: {!! $phase->description !!}

@endif
@if ($open->isNotEmpty())
Open items in this phase, in plan order:
@foreach ($open as $item)
- {!! $item->text !!}{!! $featureLine($item->feature) !!}
@endforeach

@endif
@if ($doneUnverified->isNotEmpty())
Ticked done, but the linked feature isn't verified yet:
@foreach ($doneUnverified as $item)
- {!! $item->text !!}{!! $featureLine($item->feature) !!}
@endforeach

@endif
What to do:
1. Run `unseen status --json` and `unseen roadmap show`, and read this phase and the features above.
@switch ($action)
@case('unblock')
2. Find out what blocks this phase (the last reports, open unresolved work, the plan docs, the code). If you can remove the blocker, do it and continue with the open items. If it needs a person (a decision, access, a client input), stop and tell me exactly what is needed and from whom.
3. Once unblocked, work through the open items as for any phase: build each linked feature until its acceptance criteria hold, with tests linked as checks, and set it to implemented when fully built.
@break
@case('close')
2. The plan says this phase is done. Check that every item really is fully built in the code; if one isn't, untick it.
3. For each linked feature that isn't verified, make sure tests prove its acceptance criteria and are linked as checks (`unseen api POST /features/<id>/checks --data '{"pattern":"…"}'`), so CI test runs can verify it. Fix anything the tests show is broken, and set such features back to in_progress until fixed.
@break
@default
2. Work through the open items in plan order. For each one: if it is a tracked feature, set it in progress (`unseen api PATCH /features/<id> --data '{"status":"in_progress"}'`) and build it until its acceptance criteria hold; if it isn't tracked yet but is a feature, record it first (`unseen api POST /projects/{!! $phase->project_id !!}/features …`) and link the roadmap item to it. Follow the project's existing patterns and its docs (PRD, plan) for what each item means.
3. Write tests that prove each feature's acceptance criteria, run them, and link them as checks. When a feature is fully built: `unseen api PATCH /features/<id> --data '{"status":"implemented","status_note":"…"}'`.
@if ($doneUnverified->isNotEmpty())
   Also cover the ticked items whose features aren't verified (listed above): link tests that prove them, and if one turns out broken, fix it (or untick it and set its feature back to in_progress).
@endif
@endswitch
4. Tick each item that is fully built (and only those), then re-sync the whole roadmap: `unseen roadmap show --json` to start from, edit, `unseen roadmap sync --file <file outside the repo>`. Keep the phase keys stable, and update the repository's own plan doc to match if it has one.
5. Never mark anything verified: only CI test runs (`unseen verify`) verify. Don't invent scope: if an item is unclear, ask me.
6. Finish with `unseen report`: the features you changed with their statuses, the tests you actually ran and their real results, and what is still open in `unresolved`. If the phase is too big for one session, do the first items, report, and tell me what is left. Tell me the report URL.
Never put secrets, `.env` values or personal data into Unseen.
