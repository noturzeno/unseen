<div>
    <div class="page-head">
        <div>
            <a href="{{ route('reports.index', $report->project) }}" wire:navigate class="back">Reports for {{ $report->project->name }}</a>
            <h1>Report #{{ $report->id }}</h1>
            <p class="muted small">
                {{ $report->source->label() }}{{ $report->agent ? ' ('.$report->agent.')' : '' }}
                @if ($report->user) · submitted by {{ $report->user->name }} @endif
                · {{ $report->reported_at->format('Y-m-d H:i') }}
                @if ($report->confidence) · reporter's confidence: {{ $report->confidence->label() }} @endif
            </p>
        </div>
    </div>

    <div class="card">
        @if ($report->prompt)
            <h2>Asked</h2>
            <p style="white-space: pre-line">{{ $report->prompt }}</p>
        @endif
        <h2>Summary</h2>
        <p style="white-space: pre-line">{{ $report->summary }}</p>
        @if ($report->limitations)
            <h2>Limitations</h2>
            <p style="white-space: pre-line">{{ $report->limitations }}</p>
        @endif
        @if ($report->git_branch || $report->git_commit || $report->diff_ref)
            <p class="muted small">
                @if ($report->git_branch) Branch <code>{{ $report->git_branch }}</code> @endif
                @if ($report->git_commit) · Commit <code>{{ $report->git_commit }}</code> @endif
                @if ($report->diff_ref) · Diff <code>{{ $report->diff_ref }}</code> @endif
            </p>
        @endif
    </div>

    <div class="grid">
        <div class="card">
            <h2>Features affected</h2>
            @if ($report->features->isEmpty())
                <p class="muted small">No features linked.</p>
            @else
                <ul class="plain">
                    @foreach ($report->features as $feature)
                        <li>
                            <a href="{{ route('features.show', $feature) }}" wire:navigate>{{ $feature->name }}</a>
                            @if ($feature->pivot->claimed_status)
                                <span class="muted small">claimed:</span>
                                <x-status-badge :status="\App\Enums\FeatureStatus::from($feature->pivot->claimed_status)" />
                            @endif
                            @if ($feature->pivot->note) <div class="muted small">{{ $feature->pivot->note }}</div> @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>

        <div class="card">
            <h2>Tests run (as reported)</h2>
            @if (empty($report->tests))
                <p class="muted small">No tests reported.</p>
            @else
                <ul class="plain">
                    @foreach ($report->tests as $test)
                        @php($outcome = \App\Enums\TestOutcome::from($test['outcome']))
                        <li class="small">
                            <span @class(['badge', 'ok' => $outcome->value === 'passed', 'bad' => in_array($outcome->value, ['failed', 'error'])])>{{ $outcome->label() }}</span>
                            <code>{{ $test['command'] }}</code>
                            @if (! empty($test['summary'])) <div class="muted">{{ $test['summary'] }}</div> @endif
                        </li>
                    @endforeach
                </ul>
                <p class="muted small">Reported by the submitter, not re-run by {{ config('app.name') }}.</p>
            @endif
        </div>

        <div class="card">
            <h2>Unresolved work</h2>
            @if ($report->unresolvedItems->isEmpty())
                <p class="muted small">Nothing reported as unresolved.</p>
            @else
                <ul class="plain">
                    @foreach ($report->unresolvedItems as $item)
                        <li class="small">{{ $item->text }}
                            @if ($item->isOpen()) <span class="badge warn">Open</span> @else <span class="badge ok">Done</span> @endif
                        </li>
                    @endforeach
                </ul>
            @endif
        </div>
    </div>

    @if ($report->ticketUpdates->isNotEmpty())
        <div class="card">
            <h2>Tickets</h2>
            <ul class="plain">
                @foreach ($report->ticketUpdates as $update)
                    <li class="small">
                        <span class="ticket-ref">{{ $update->ticket->label() }}</span>
                        <a href="{{ route('tickets.show', $update->ticket_id) }}" wire:navigate>{{ $update->ticket->title }}</a>
                        @if ($update->to_status) → <span class="badge {{ $update->to_status->tone() }}">{{ $update->to_status->label() }}</span> @endif
                        @if ($update->body) <div class="muted">{{ $update->body }}</div> @endif
                    </li>
                @endforeach
            </ul>
        </div>
    @endif

    <div class="card">
        <h2>Changed files</h2>
        @if (empty($report->changed_files))
            <p class="muted small">No files listed.</p>
        @else
            <ul class="plain small">
                @foreach ($report->changed_files as $file)
                    <li><code>{{ $file['path'] }}</code> @if (! empty($file['change'])) <span class="muted">{{ $file['change'] }}</span> @endif</li>
                @endforeach
            </ul>
        @endif
    </div>
</div>
