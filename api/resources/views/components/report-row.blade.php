@props(['report'])
@php($counts = $report->testCounts())
<li>
    <a href="{{ route('reports.show', $report) }}" wire:navigate>{{ Str::limit($report->summary, 120) }}</a>
    <div class="muted small">
        {{ $report->source->label() }}{{ $report->agent ? ' ('.$report->agent.')' : '' }}
        · {{ $report->reported_at->diffForHumans() }}
        · {{ $report->features_count ?? $report->features->count() }} {{ Str::plural('feature', $report->features_count ?? $report->features->count()) }}
        @if ($report->tests_failed_count)
            · <span class="badge bad">{{ $report->tests_failed_count }} failing {{ Str::plural('test', $report->tests_failed_count) }}</span>
        @elseif (! empty($counts['passed']))
            · <span class="badge">{{ $counts['passed'] }} passed (reported)</span>
        @endif
        @if (count($report->unresolved ?? []))
            · <span class="badge warn">{{ count($report->unresolved) }} unresolved</span>
        @endif
    </div>
</li>
