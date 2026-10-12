@if ($single)
@php($item = $items->first())
Resolve open item #{!! $item->id !!} in the Unseen project "{!! $project !!}" (project #{!! $projectId !!}):

{!! $item->text !!}

@if ($item->decision)
{!! $item->decidedBy?->name ?? 'A person' !!} decided{!! $item->decision_note ? ' ('.$item->decision_note.')' : '' !!}:
{!! $item->decision !!}
Carry out this decision. Don't second-guess it; if it can't be done as decided, stop and tell me why.

@elseif ($item->needsDecision())
This item waits on {!! $item->waiting_on->value === 'client' ? 'the client' : 'me (my team)' !!}@if ($item->decision_note): {!! $item->decision_note !!}@endif.
The decision: ___ (fill this in before sending; if it is still blank, don't start: tell me what you need instead.)

@endif
@if ($item->report)
It was left open by report #{!! $item->report->id !!}: "{!! \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $item->report->summary), 300) !!}" ({!! $url !!}/reports/{!! $item->report->id !!})
@endif
@else
Work through the open items in the Unseen project "{!! $project !!}" (project #{!! $projectId !!}). They are things earlier reports said were left unfinished:

@foreach ($items->reject(fn ($i) => $i->needsDecision()) as $item)
- #{!! $item->id !!}: {!! \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $item->text), 600) !!}
@if ($item->decision)
  Decided ({!! $item->decidedBy?->name ?? 'a person' !!}): {!! \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $item->decision), 600) !!} Carry this out as decided.
@endif
@endforeach
@php($waiting = $items->filter(fn ($i) => $i->needsDecision()))
@if ($waiting->isNotEmpty())

Skip these: they wait on a decision from the client or from me, so don't work on them or guess:
@foreach ($waiting as $item)
- #{!! $item->id !!} (waiting on {!! $item->waiting_on->value === 'client' ? 'the client' : 'me' !!}): {!! \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $item->decision_note ?: $item->text), 300) !!}
@endforeach
@endif
@if ($more)
- …and {!! $more !!} more: run `unseen status --json` to see them all.
@endif
@endif

This repository should already be linked to Unseen (.unseen.json). If it isn't, stop and ask me for a setup prompt.

What to do:
1. Run `unseen status --json` and read the open unresolved work and the features it mentions (requirement ids such as BR-01 match feature names).
@if ($single)
2. Decide what kind of item this is:
@else
2. Sort the items: first defects and security or privacy problems, then missing work, then tests and CI, then polish. For each one, decide what kind of item it is:
@endif
   - Something code can fix (a defect, missing work, missing tests or CI setup): fix it, with tests that prove the fix.
   - Something that needs a person (a product decision, a client figure, access you don't have, a review): don't guess and don't fake it. Record who it waits on with `unseen api PATCH /unresolved/<id> --data '{"waiting_on":"client|team","decision_note":"<exactly what is needed>"}'` and tell me.
   - Something no longer true: check, and say so in your report.
3. When a fix adds or changes a feature, record it: status with `unseen api PATCH /features/<id> --data '{"status":"…","status_note":"…"}'`, new tests as checks with `unseen api POST /features/<id>/checks --data '{"pattern":"…"}'`. Never mark anything verified: only CI test runs (`unseen verify`) verify.
4. If the roadmap changes, re-sync it (`unseen roadmap sync --file <file>`).
5. Finish with `unseen report`. Put the ids of items you fully resolved in `resolves` (e.g. `"resolves": [{!! $items->pluck('id')->take(3)->implode(', ') !!}]`) and anything still open, or newly found, in `unresolved` (as `{"text":"…","waiting_on":"client|team|agent","decision_note":"…"}` so the dashboard shows who has to act). Don't resolve an item you only partly fixed or that still needs a person. Tell me the report URL.
@if (! $single)
If there is too much for one session, do the most important items first, report them, and tell me what is left.
@endif
Never put secrets, `.env` values or personal data into Unseen.
