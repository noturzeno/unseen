<?php

namespace App\Livewire;

use App\Enums\PhaseStatus;
use App\Models\Project;
use App\Services\RoadmapService;
use App\Support\PhasePrompt;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Read-only roadmap: the project's phases and implementation lists, as synced by its coding agent.
 */
class RoadmapIndex extends Component
{
    public Project $project;

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
    }

    public function render(RoadmapService $roadmap)
    {
        Gate::authorize('view', $this->project);

        $phases = $roadmap->phases($this->project);
        $phases->each(fn ($phase) => $phase->setRelation('project', $this->project));
        // Prompts for the project's coding agent (no token in them; the repo is already linked).
        $prompts = $phases->mapWithKeys(fn ($phase) => [$phase->id => PhasePrompt::build($phase)]);
        $current = $phases->first(fn ($phase) => $phase->status !== PhaseStatus::Done);

        return view('livewire.roadmap-index', [
            'phases' => $phases,
            'prompts' => $prompts,
            'current' => $current,
        ])->title('Roadmap · '.$this->project->name);
    }
}
