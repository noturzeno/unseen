<div>
    <div class="page-head">
        <div>
            <a href="{{ route('projects.show', $project) }}" wire:navigate class="back">{{ $project->name }}</a>
            <h1>Suggested features</h1>
            <p class="muted">Found by scanning the repository. Code existing is a sign a feature might be there, not proof that it works. Coding agents review each one and add, merge or dismiss it.</p>
        </div>
    </div>

    <x-project-tabs :project="$project" />

    <div class="card small">
        @if ($latestScan)
            Last scan {{ $latestScan->created_at->diffForHumans() }}
            @if ($latestScan->git_branch) on <code>{{ $latestScan->git_branch }}</code> @endif
            @if ($latestScan->git_commit) at <code>{{ Str::limit($latestScan->git_commit, 10, '') }}</code> @endif
            · {{ $latestScan->candidates_count }} candidates, {{ $latestScan->new_count }} new.
        @else
            No scans yet. In the linked repository run <code>unseen scan</code>.
        @endif
    </div>

    <div class="filters">
        @foreach (\App\Enums\SuggestionStatus::cases() as $case)
            <button type="button" wire:click="$set('status', '{{ $case->value }}')" @class(['btn', 'primary' => $current === $case])>
                {{ $case === \App\Enums\SuggestionStatus::Pending ? 'Waiting for review' : ucfirst($case->value) }} ({{ $counts[$case->value] ?? 0 }})
            </button>
        @endforeach
    </div>

    @forelse ($suggestions as $suggestion)
        <div class="card" wire:key="s-{{ $suggestion->id }}">
            <div class="page-head" style="margin-bottom: 8px">
                <div>
                    <h2 style="margin-bottom: 2px">{{ $suggestion->name }}</h2>
                    <div class="muted small">
                        <span class="badge">Detected in the repository</span>
                        @if ($suggestion->suggested_category) · {{ $suggestion->suggested_category }} @endif
                        @if ($suggestion->suggested_status) · docs say <x-status-badge :status="$suggestion->suggested_status" /> @endif
                        @if ($suggestion->last_scan_id !== $latestScan?->id) · <span class="badge warn">Not seen in the latest scan</span> @endif
                    </div>
                    @if ($suggestion->description) <p class="small">{{ $suggestion->description }}</p> @endif
                </div>
            </div>

            @if ($suggestion->matchedFeature && $current === \App\Enums\SuggestionStatus::Pending)
                <p class="small"><span class="badge info">Possible duplicate</span> You already track
                    <a href="{{ route('features.show', $suggestion->matchedFeature) }}" wire:navigate>{{ $suggestion->matchedFeature->name }}</a>. </p>
            @endif
            @if ($suggestion->feature)
                <p class="small">{{ $current === \App\Enums\SuggestionStatus::Accepted ? 'Became' : 'Merged into' }}
                    <a href="{{ route('features.show', $suggestion->feature) }}" wire:navigate>{{ $suggestion->feature->name }}</a>.</p>
            @endif

            <details>
                <summary class="small">Evidence ({{ count($suggestion->evidence) }})</summary>
                <ul class="plain small">
                    @foreach (array_slice($suggestion->evidence, 0, 25) as $item)
                        <li><span class="badge">{{ $item['type'] }}</span> <code>{{ $item['path'] }}{{ isset($item['line']) ? ':'.$item['line'] : '' }}</code>
                            @if (! empty($item['detail'])) <span class="muted">{{ $item['detail'] }}</span> @endif</li>
                    @endforeach
                </ul>
                @if (count($suggestion->evidence) > 25) <p class="muted small">…and {{ count($suggestion->evidence) - 25 }} more.</p> @endif
            </details>

        </div>
    @empty
        <div class="card"><p class="muted">{{ $current === \App\Enums\SuggestionStatus::Pending ? 'Nothing waiting for review.' : 'None.' }}</p></div>
    @endforelse

    {{ $suggestions->links('partials.pagination') }}
</div>
