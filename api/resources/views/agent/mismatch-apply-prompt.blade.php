Apply the decisions made on PRD vs design mismatches in the Unseen project "{!! $project !!}" (project #{!! $projectId !!}).

@foreach ($items as $m)
- Mismatch #{!! $m->id !!}{!! $m->reference ? ' ('.$m->reference.')' : '' !!}: {!! $m->title !!}@if ($m->feature) [feature #{!! $m->feature_id !!} {!! $m->feature->name !!}]@endif

  PRD says: {!! \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $m->prd_says), 500) !!}
  Design says: {!! \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $m->design_says), 500) !!}
  Built now: {!! $m->built_to->label() !!}{!! $m->built_note ? ' — '.\Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $m->built_note), 300) : '' !!}
  Decision: {!! $m->decision->label() !!}. {!! $m->decision->action() !!}@if ($m->decision_note) Note: {!! preg_replace('/\s+/', ' ', $m->decision_note) !!}@endif

@endforeach
This repository should already be linked to Unseen (.unseen.json). If it isn't, stop and ask me for a setup prompt.

For each one:
1. Do what the decision says. "Follow the PRD": change the code (with tests) so it does what the PRD says. "Keep the design": leave the behaviour and update the PRD text in the repository to describe what was built. "Something else": follow the note, and update the PRD to match.
2. If the decision is unclear or can't be done as stated, stop and ask me. Don't reinterpret it.
3. Mark it applied: `unseen mismatch applied <id> --note "<what you changed>"`.
4. Update affected features (status, acceptance criteria) with `unseen api`, as usual. Never mark anything verified.

Finish with `unseen report` listing the changed features and files. Tell me the report URL.
Never put secrets, `.env` values or personal data into Unseen.
