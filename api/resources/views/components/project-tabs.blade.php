@props(['project'])
@php
    $pending = $project->featureSuggestions()->where('status', \App\Enums\SuggestionStatus::Pending)->count();
    $openTickets = $project->tickets()->open()->count();
    $undecided = $project->specMismatches()->where('status', 'open')->count();
    $open = $project->unresolvedItems()->where('status', \App\Models\UnresolvedItem::OPEN)->count();
    $tabs = [
        ['Overview', route('projects.show', $project), request()->routeIs('projects.show'), null],
        ['Roadmap', route('roadmap.index', $project), request()->routeIs('roadmap.*'), null],
        ['PRD vs design', route('mismatches.index', $project), request()->routeIs('mismatches.*'), $undecided],
        ['Tickets', route('tickets.index', $project), request()->routeIs('tickets.*'), $openTickets],
        ['Reports', route('reports.index', $project), request()->routeIs('reports.*'), null],
        ['Suggestions', route('suggestions.index', $project), request()->routeIs('suggestions.*'), $pending],
        ['Unresolved work', route('unresolved.index', $project), request()->routeIs('unresolved.*'), $open],
        ['Settings', route('projects.edit', $project), request()->routeIs('projects.edit'), null],
    ];
@endphp
<nav class="tabs" aria-label="Project sections">
    @foreach ($tabs as [$label, $url, $active, $count])
        <a href="{{ $url }}" wire:navigate @class(['active' => $active]) @if ($active) aria-current="page" @endif>
            {{ $label }}
            @if ($count) <span @class(['count', 'hot' => in_array($label, ['Suggestions', 'PRD vs design'], true)])>{{ $count }}</span> @endif
        </a>
    @endforeach
</nav>
