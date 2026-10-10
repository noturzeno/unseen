<div>
    <div class="page-head">
        <div>
            <h1>Projects</h1>
            <p class="muted">What has been built in each project, and what has been checked.</p>
        </div>
        <div class="actions">
            <label class="small"><input type="checkbox" wire:model.live="showArchived"> Show archived</label>
            <a class="btn primary" href="{{ route('projects.create') }}" wire:navigate>New project</a>
        </div>
    </div>

    @if ($projects->isEmpty())
        <div class="card empty">
            <h2>No projects yet</h2>
            <p class="muted">Create a project to start tracking its features, agent reports and verification.</p>
            <a class="btn primary" href="{{ route('projects.create') }}" wire:navigate>Create your first project</a>
        </div>
    @else
        <div class="grid project-list">
            @foreach ($projects as $project)
                <a class="card" href="{{ route('projects.show', $project) }}" wire:navigate wire:key="project-{{ $project->id }}">
                    <h2>
                        {{ $project->name }}
                        @if ($project->isArchived()) <span class="badge">Archived</span> @endif
                    </h2>
                    <p class="muted small">{{ $project->description ? Str::limit($project->description, 140) : 'No description.' }}</p>
                    <x-progress-bars :progress="$project->progress" :total="$project->features_count"
                        :implemented="$project->implemented_features_count" :verified="$project->verified_features_count" />
                    <div class="foot small">
                        @if ($project->attention_features_count)
                            <span class="badge warn">{{ $project->attention_features_count }} need attention</span>
                        @endif
                        <span class="muted">Last activity {{ $project->lastActivityAt()?->diffForHumans() ?? 'never' }}</span>
                    </div>
                </a>
            @endforeach
        </div>
    @endif

    {{ $projects->links('partials.pagination') }}
</div>
