<?php

namespace App\Livewire;

use App\Models\Feature;
use App\Services\FeatureLifecycle;
use App\Support\DiagramPrompt;
use App\Support\FeaturePrompt;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Read-only feature page. Coding agents change features through the API (statuses,
 * criteria, checks, diagrams); trusted test runs verify them. Nothing here writes data.
 */
class FeatureDetail extends Component
{
    public Feature $feature;

    /** Which diagram tab is shown (view state only). */
    public ?int $shownDiagram = null;

    public function mount(Feature $feature): void
    {
        Gate::authorize('view', $feature->project);
    }

    public function render()
    {
        Gate::authorize('view', $this->feature->project);

        $this->feature->load(['project', 'category', 'acceptanceCriteria']);

        $history = $this->feature->statusHistory()->with('user')->limit(50)->get();
        $evidence = $this->feature->evidence()->with('developmentReport')->limit(20)->get();

        // One newest-first stream: status changes and report mentions together.
        $timeline = $history->map(fn ($change) => ['kind' => 'change', 'at' => $change->created_at, 'item' => $change])
            ->concat($evidence->map(fn ($item) => ['kind' => 'evidence', 'at' => $item->created_at, 'item' => $item]))
            ->sortByDesc('at')
            ->values();

        return view('livewire.feature-detail', [
            'history' => $history,
            'evidence' => $evidence,
            'timeline' => $timeline,
            'lifecycle' => FeatureLifecycle::steps($this->feature, $history),
            'checks' => $this->feature->checks()->with('latestResult.testRun')->get(),
            'diagrams' => $diagrams = $this->feature->diagrams()->get(),
            'diagramSync' => DiagramPrompt::build($this->feature, $diagrams),
            'repositoryEvidence' => $this->feature->suggestions()->with('lastScan')->get(),
            'next' => FeaturePrompt::build($this->feature),
            'mismatches' => ! $this->feature->project->mismatches_enabled ? collect() : $this->feature->specMismatches()->with('decidedBy:id,name')
                ->orderByRaw("case status when 'open' then 0 when 'decided' then 1 else 2 end")->latest('updated_at')->get(),
            'tickets' => $this->feature->tickets()->with('latestFeedback')->withCount('comments')
                ->orderByRaw("case when status in ('done', 'closed') then 1 else 0 end")->latest('updated_at')->limit(20)->get(),
        ])->title($this->feature->name);
    }
}
