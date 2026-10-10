@props(['project', 'phases'])
{{-- Compact roadmap for the overview: one row per phase, current phase highlighted. --}}
@php($current = $phases->first(fn ($p) => $p->status !== \App\Enums\PhaseStatus::Done))
<div class="card">
    <div class="card-head">
        <h2>Roadmap</h2>
        <a href="{{ route('roadmap.index', $project) }}" wire:navigate>View all</a>
    </div>
    @if ($phases->isEmpty())
        <p class="muted small">No roadmap yet. The coding agent syncs the project's phases with <code>unseen roadmap</code>.</p>
    @else
        <ul class="plain roadmap-mini">
            @foreach ($phases as $phase)
                @php($p = \App\Services\RoadmapService::progress($phase))
                <li @class(['current' => $current && $phase->is($current)]) wire:key="rm-{{ $phase->id }}">
                    <div class="row">
                        <span class="name"><span class="phase-key">{{ $phase->key }}</span> {{ $phase->name }}</span>
                        <span class="badge {{ $phase->status->tone() }}">{{ $phase->status->label() }}</span>
                    </div>
                    @if ($p['items'])
                        <div class="progress"><div class="row small muted"><span>{{ $p['done'] }} / {{ $p['items'] }} done</span><span>{{ $p['percent'] }}%</span></div>
                            <div class="bar"><div @class(['fill', 'ok' => $p['percent'] === 100]) style="width: {{ $p['percent'] }}%"></div></div></div>
                    @endif
                </li>
            @endforeach
        </ul>
        @if ($project->roadmap_synced_at) <p class="muted small">Synced {{ $project->roadmap_synced_at->diffForHumans() }}</p> @endif
    @endif
</div>
