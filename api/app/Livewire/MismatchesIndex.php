<?php

namespace App\Livewire;

use App\Enums\MismatchDecision;
use App\Enums\MismatchStatus;
use App\Enums\WaitingOn;
use App\Models\Project;
use App\Services\SpecMismatchService;
use App\Support\MismatchPrompt;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * PRD vs design mismatches, side by side, and where people decide them. The one place besides
 * projects, members and tokens where the web UI writes: a decision is a person's call, never the agent's.
 */
class MismatchesIndex extends Component
{
    use WithPagination;

    public Project $project;

    /** team | client | decided | applied, or "" for everything that still needs a person or the agent. */
    #[Url]
    public string $show = '';

    /** Notes being typed, per mismatch id. */
    public array $notes = [];

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
        abort_unless($project->mismatches_enabled, 404); // turned off in the project settings
    }

    public function updatedShow(): void
    {
        $this->resetPage();
    }

    public function decide(int $id, string $decision, SpecMismatchService $mismatches): void
    {
        Gate::authorize('update', $this->project);
        $choice = MismatchDecision::tryFrom($decision) ?? abort(422);
        $mismatch = $this->project->specMismatches()->findOrFail($id);

        $mismatches->decide($mismatch, $choice, $this->notes[$id] ?? null, auth()->user());
        unset($this->notes[$id]);
    }

    public function reopen(int $id, SpecMismatchService $mismatches): void
    {
        Gate::authorize('update', $this->project);
        $mismatches->reopen($this->project->specMismatches()->findOrFail($id));
    }

    public function render(SpecMismatchService $mismatches)
    {
        Gate::authorize('view', $this->project);

        // Unknown filter values from the URL are ignored rather than trusted.
        $show = in_array($this->show, ['team', 'client', 'decided', 'applied'], true) ? $this->show : '';

        $items = $this->project->specMismatches()->with(['feature:id,name', 'decidedBy:id,name'])
            ->when($show === '', fn ($q) => $q->where('status', '!=', MismatchStatus::Applied))
            ->when(in_array($show, ['team', 'client'], true), fn ($q) => $q->where('status', MismatchStatus::Open)->where('decide_by', WaitingOn::from($show)))
            ->when(in_array($show, ['decided', 'applied'], true), fn ($q) => $q->where('status', $show))
            ->orderByRaw("case when status = 'open' and decide_by = 'team' then 0 when status = 'open' then 1 when status = 'decided' then 2 else 3 end")
            ->latest('updated_at')->latest('id')
            ->paginate(20);

        return view('livewire.mismatches-index', [
            'items' => $items,
            'counts' => $mismatches->counts($this->project),
            'current' => $show,
            'findPrompt' => MismatchPrompt::find($this->project),
            'applyPrompt' => MismatchPrompt::apply($this->project),
        ])->title('PRD vs design · '.$this->project->name);
    }
}
