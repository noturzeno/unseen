<?php

namespace App\Livewire;

use App\Enums\SuggestionStatus;
use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only list of repository scan suggestions and what became of them. Coding agents
 * accept, merge or dismiss suggestions through the API.
 */
class SuggestionsReview extends Component
{
    use WithPagination;

    public Project $project;

    #[Url]
    public string $status = 'pending';

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        Gate::authorize('view', $this->project);
        $status = SuggestionStatus::tryFrom($this->status) ?? SuggestionStatus::Pending;

        return view('livewire.suggestions-review', [
            'current' => $status,
            'suggestions' => $this->project->featureSuggestions()
                ->with(['feature', 'matchedFeature'])
                ->where('status', $status)
                ->orderBy('name')
                ->paginate(20),
            'counts' => $this->project->featureSuggestions()->toBase()
                ->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status'),
            'latestScan' => $this->project->repositoryScans()->latest()->latest('id')->first(),
        ])->title('Suggestions · '.$this->project->name);
    }
}
