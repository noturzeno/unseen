<?php

namespace App\Livewire;

use App\Models\Project;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;
use Livewire\WithPagination;

class ReportsIndex extends Component
{
    use WithPagination;

    public Project $project;

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
    }

    public function render()
    {
        Gate::authorize('view', $this->project);

        return view('livewire.reports-index', [
            'reports' => $this->project->developmentReports()
                ->with('user:id,name')
                ->withCount('features')
                ->latest('reported_at')
                ->latest('id')
                ->paginate(20),
        ])->title('Reports · '.$this->project->name);
    }
}
