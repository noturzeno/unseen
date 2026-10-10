Sort the open unresolved items in the Unseen project "{!! $project !!}" (project #{!! $projectId !!}) by who has to act, so the dashboard shows which ones need a decision from me or from the client. Don't fix anything in this task.

@foreach ($items as $item)
- #{!! $item->id !!}@if ($item->waiting_on) (now: {!! $item->waiting_on->value !!})@endif: {!! \Illuminate\Support\Str::limit(preg_replace('/\s+/', ' ', $item->text), 600) !!}
@endforeach
@if ($more)
- …and {!! $more !!} more: run `unseen api GET /projects/{!! $projectId !!}/unresolved?waiting_on=unsorted` to see them.
@endif

This repository should already be linked to Unseen (.unseen.json). If it isn't, stop and ask me for a setup prompt.

For each item, check the code and the project's docs, then decide who it waits on:
- `client`: the client has to decide, confirm, approve or provide something (a business rule, content, sign-off, their account or property IDs, legal rights).
- `team`: I or my team have to decide or provide something (a product or technical decision, access, an API key, a paid service, a manual operator step, a review).
- `agent`: no decision is needed; a coding agent can just do it (a defect, missing code, missing tests).

Record it, saying in one sentence exactly what is needed:
`unseen api PATCH /unresolved/<id> --data '{"waiting_on":"client","decision_note":"Confirm whether business users may reply to listings (BUS-04)."}'`
For `agent` items the note says what to build.

An item that mixes several things (a list of gaps with different owners): split it. Submit one `unseen report` with `"summary":"Sorted open items by who has to act"`, one `unresolved` entry per part (`{"text":"…","waiting_on":"client|team|agent","decision_note":"…"}`), and the mixed item's id in `resolves`. Keep requirement ids (e.g. BR-02) in the text.

When done, tell me the counts per group and list the questions for me and for the client.
Never put secrets, `.env` values or personal data into Unseen.
