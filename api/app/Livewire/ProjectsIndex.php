<?php

namespace App\Livewire;

use App\Models\Project;
use App\Services\ProjectDashboard;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

#[Title('Projects')]
class ProjectsIndex extends Component
{
    use WithPagination;

    #[Url]
    public bool $showArchived = false;

    public function updatedShowArchived(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        Gate::authorize('viewAny', Project::class);

        $projects = Project::query()
            ->accessibleBy(auth()->user())
            ->withProgressCounts()
            ->when(! $this->showArchived, fn ($q) => $q->whereNull('archived_at'))
            ->latest('updated_at')
            ->paginate(20);

        $projects->getCollection()->each(fn (Project $p) => $p->setAttribute('progress', ProjectDashboard::progress(
            $p->implemented_features_count, $p->verified_features_count, $p->features_count,
        )));

        return view('livewire.projects-index', ['projects' => $projects]);
    }
}
