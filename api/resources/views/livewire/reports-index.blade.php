<div>
    <div class="page-head">
        <div>
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="back">{{ $project->name }}</a>
            <h1>Development reports</h1>
            <p class="muted">What coding agents (or people) said they did. These are claims; checking them is a separate step.</p>
        </div>
    </div>

    <x-project-tabs :project="$project" />

    <div class="card">
        @if ($reports->isEmpty())
            <p class="muted">No reports yet. Coding agents send them through the API after each task.</p>
        @else
            <ul class="plain">
                @foreach ($reports as $report)
                    <x-report-row :report="$report" wire:key="r-{{ $report->id }}" />
                @endforeach
            </ul>
            {{ $reports->links('partials.pagination') }}
        @endif
    </div>
</div>
