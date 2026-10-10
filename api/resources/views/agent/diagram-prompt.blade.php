@php($id = $feature->id)
@if ($diagrams->isEmpty())
Draw diagrams for the Unseen feature "{!! $name !!}" (feature #{!! $id !!} in project "{!! $project !!}") from the current code.
@else
Sync the diagrams of the Unseen feature "{!! $name !!}" (feature #{!! $id !!} in project "{!! $project !!}") with the current code.
@endif
Unseen page: {!! $url !!}/features/{!! $id !!}

This repository should already be linked to Unseen (.unseen.json). If it isn't, stop and ask me for a setup prompt.

@if ($feature->description)
What it does:
{!! $feature->description !!}

@endif
@if ($feature->acceptanceCriteria->isNotEmpty())
Acceptance criteria:
@foreach ($feature->acceptanceCriteria as $criterion)
- {!! $criterion->description !!}
@endforeach

@endif
@if ($diagrams->isNotEmpty())
Diagrams in Unseen now:
@foreach ($diagrams as $diagram)
- #{!! $diagram->id !!} {!! $diagram->type->value !!} "{!! str_replace('"', "'", $diagram->title) !!}" (last updated {!! $diagram->updated_at?->toDateTimeString() !!} UTC)
@endforeach

@endif
What to do (diagrams only: don't change code, statuses, criteria or checks):
@if ($diagrams->isNotEmpty())
1. Read the current definitions: `unseen api GET /features/{!! $id !!}/diagrams`.
2. Trace the feature through the code as it is now (routes, controllers, services, jobs, pages, emails) and compare each diagram step by step: actors and participants, calls and endpoints, data and file formats, branches and error paths.
3. Where a diagram no longer matches the code, fix it: write `{"definition":"…"}` (and `"title"` if needed) to a file outside the repository and run `unseen api PATCH /diagrams/<id> --file <that file>`. Draw what the code does, not what the criteria say; if they disagree, put that in `unresolved` in step 5.
4. Delete a diagram only if the behaviour it shows is gone: `unseen api DELETE /diagrams/<id>`. Add one if an important flow is missing: `unseen api POST /features/{!! $id !!}/diagrams --file <json outside the repo>`.
@else
1. Trace the feature through the code as it is now (routes, controllers, services, jobs, pages, emails).
2. Add the diagrams that explain it best (usually one): a flowchart of its steps, a use case diagram of who uses it, or a sequence diagram of how the parts talk to each other. Write `{"type":"flowchart|use_case|sequence","title":"…","definition":"<Mermaid>"}` to a file outside the repository and run `unseen api POST /features/{!! $id !!}/diagrams --file <that file>`.
3. Draw what the code does, not what the criteria say; if they disagree, put that in `unresolved` in step 5.
4. Use cases: draw them as `flowchart LR` with actors as `Name[Customer]:::actor` boxes and use cases as `([Pay invoice])` pills inside a `subgraph`, plus `classDef actor stroke-dasharray: 4 3`.
@endif
5. Finish with `unseen report` for feature #{!! $id !!}: the commit you checked, and for each diagram whether it was already up to date, updated (what changed), removed or added. Tell me the report URL.
Keep diagrams about behaviour: no secrets, `.env` values, real customer data or internal hostnames.
