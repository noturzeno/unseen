<div>
    <div class="page-head">
        <div>
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="back">{{ $project->name }}</a>
            <h1>Tickets</h1>
            <p class="muted">Feature, bug and change-request tickets and where each is in the flow: build, QA, client review, done. Coding agents record them, with the QA and client comments they are given.</p>
        </div>
    </div>

    <x-project-tabs :project="$project" />

    @php($bs = $counts['by_status'])
    <div class="card">
        <h2>Flow</h2>
        @if ($counts['total'] === 0)
            <p class="muted small">No tickets yet. A coding agent records them with <code>unseen ticket add</code> when you give it a ticket, and adds QA and client comments with <code>unseen ticket comment</code>.</p>
        @else
            <ol class="flow-board" aria-label="Tickets per stage">
                @foreach ([
                    ['Open', ['open'], ''],
                    ['In progress', ['in_progress'], 'info'],
                    ['QA', ['ready_for_qa', 'qa_failed'], 'info'],
                    ['Client review', ['client_review', 'changes_requested'], 'info'],
                    ['Done', ['done'], 'ok'],
                ] as [$label, $statuses, $tone])
                    <li>
                        <span class="lbl">{{ $label }}</span>
                        <span class="num">{{ collect($statuses)->sum(fn ($s) => $bs[$s]) }}</span>
                        @foreach ($statuses as $s)
                            @if (in_array($s, ['qa_failed', 'changes_requested'], true) && $bs[$s])
                                <button type="button" class="badge {{ \App\Enums\TicketStatus::from($s)->tone() }}" wire:click="$set('state', '{{ $s }}')">{{ $bs[$s] }} {{ \App\Enums\TicketStatus::from($s)->label() }}</button>
                            @endif
                        @endforeach
                    </li>
                @endforeach
            </ol>
            @if ($bs['closed']) <p class="muted small">{{ $bs['closed'] }} closed without finishing.</p> @endif
        @endif
    </div>

    <div class="filters">
        <button type="button" wire:click="$set('state', 'open')" @class(['btn', 'primary' => $current === 'open'])>Open ({{ $counts['open'] }})</button>
        <button type="button" wire:click="$set('state', 'finished')" @class(['btn', 'primary' => $current === 'finished'])>Done or closed ({{ $counts['total'] - $counts['open'] }})</button>
        <button type="button" wire:click="$set('state', 'all')" @class(['btn', 'primary' => $current === 'all'])>All ({{ $counts['total'] }})</button>
        <select wire:model.live="state" aria-label="Filter by status">
            <option value="" disabled>Exact status…</option>
            @foreach (\App\Enums\TicketStatus::cases() as $case)
                <option value="{{ $case->value }}">Only {{ mb_strtolower($case->label()) }} ({{ $bs[$case->value] }})</option>
            @endforeach
        </select>
        <select wire:model.live="type" aria-label="Filter by type">
            <option value="">All types</option>
            @foreach (\App\Enums\TicketType::cases() as $case)
                <option value="{{ $case->value }}">{{ $case->label() }}</option>
            @endforeach
        </select>
    </div>

    <div class="dash">
        <div class="dash-main">
            <div class="card">
                @if ($tickets->isEmpty())
                    <p class="muted">No tickets match.</p>
                @else
                    <ul class="plain tickets">
                        @foreach ($tickets as $ticket)
                            <x-ticket-row :ticket="$ticket" wire:key="t-{{ $ticket->id }}" />
                        @endforeach
                    </ul>
                    {{ $tickets->links('partials.pagination') }}
                @endif
            </div>
        </div>
        <aside class="dash-side">
            <div class="card">
                <h2>QA &amp; client feedback</h2>
                @if ($feedback->isEmpty())
                    <p class="muted small">No QA or client comments yet.</p>
                @else
                    <x-feedback-list :comments="$feedback" />
                @endif
            </div>
        </aside>
    </div>
</div>
