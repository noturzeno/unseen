<div>
    <div class="page-head">
        <div>
            <a href="{{ route('tickets.index', $ticket->project) }}" wire:navigate class="back">{{ $ticket->project->name }} · Tickets</a>
            <h1><span class="ticket-ref">{{ $ticket->label() }}</span> {{ $ticket->title }}</h1>
            <div class="meta small muted">
                <span class="badge {{ $ticket->status->tone() }}">{{ $ticket->status->label() }}</span>
                <span>{{ $ticket->type->label() }}</span>
                @if ($ticket->priority) <span>· {{ ucfirst($ticket->priority) }} priority</span> @endif
                @if ($ticket->raised_by) <span>· raised by {{ $ticket->raised_by->label() }}</span> @endif
                @if ($ticket->feature) <span>· feature <a href="{{ route('features.show', $ticket->feature) }}" wire:navigate>{{ $ticket->feature->name }}</a></span> @endif
            </div>
        </div>
    </div>

    <div class="card lifecycle-card">
        <ol class="lifecycle five" aria-label="Ticket flow">
            @foreach ($flow as $step)
                <li class="step {{ $step['state'] }}" @if (! in_array($step['state'], ['done', 'upcoming'], true)) aria-current="step" @endif>
                    <span class="dot" aria-hidden="true">
                        @if ($step['state'] === 'done')
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round" stroke-linejoin="round"><path d="M3.5 8.5 6.5 11.5 12.5 4.5"/></svg>
                        @elseif (in_array($step['state'], ['failed', 'stopped'], true))
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M5 5l6 6M11 5l-6 6"/></svg>
                        @elseif ($step['state'] === 'blocked')
                            <svg viewBox="0 0 16 16" fill="none" stroke="currentColor" stroke-width="2.4" stroke-linecap="round"><path d="M8 4.5v4.5M8 11.5v.01"/></svg>
                        @else
                            {{ $loop->iteration }}
                        @endif
                    </span>
                    <span class="step-text">
                        <strong>{{ $step['label'] }}</strong>
                        @if ($step['hint']) <span class="hint-text">{{ $step['hint'] }}</span> @endif
                        <span class="when">{{ $step['at'] ? $step['at']->format('j M Y') : '—' }}</span>
                    </span>
                </li>
            @endforeach
        </ol>
        <div class="facts four">
            <div class="fact"><span class="lbl">Thread entries</span><span class="val">{{ $thread->count() }}</span></div>
            <div class="fact"><span class="lbl">QA &amp; client comments</span><span class="val">{{ $feedbackCount }}</span></div>
            <div class="fact"><span class="lbl">Sent to QA</span><span class="val">{{ $qaRounds }} {{ Str::plural('time', $qaRounds) }}</span></div>
            <div class="fact"><span class="lbl">Opened</span><span class="val date">{{ $ticket->created_at->format('j M Y') }}</span></div>
        </div>
    </div>

    @if ($ticket->description)
        <div class="card">
            <h2>Description</h2>
            <p class="prewrap">{{ $ticket->description }}</p>
        </div>
    @endif

    <div class="card">
        <div class="card-head"><h2>Thread</h2><span class="muted small">Comments and status changes, oldest first. Recorded by the coding agent from what each person said.</span></div>
        <ul class="plain thread">
            @foreach ($thread as $entry)
                <li wire:key="c-{{ $entry->id }}" @class(['feedback-entry' => in_array($entry->author_role, \App\Enums\CommentRole::feedback(), true) && $entry->body])>
                    <div class="small">
                        <span class="badge {{ $entry->author_role->tone() }}">{{ $entry->author_role->label() }}</span>
                        @if ($entry->author_name) <strong>{{ $entry->author_name }}</strong> @endif
                        @if ($entry->to_status)
                            @if ($entry->from_status) moved it from {{ $entry->from_status->label() }} to @else opened it as @endif
                            <span class="badge {{ $entry->to_status->tone() }}">{{ $entry->to_status->label() }}</span>
                        @endif
                        @if ($entry->report) · in <a href="{{ route('reports.show', $entry->report) }}" wire:navigate>report #{{ $entry->report->id }}</a> @endif
                    </div>
                    @if ($entry->body) <div class="prewrap">{{ $entry->body }}</div> @endif
                    <div class="muted small">
                        {{ $entry->source->label() }}{{ $entry->user ? ' ('.$entry->user->name.')' : '' }} · {{ $entry->created_at->format('j M Y, H:i') }}
                    </div>
                </li>
            @endforeach
        </ul>
    </div>
</div>
