<?php

namespace App\Livewire;

use App\Models\TestRun;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

class TestRunDetail extends Component
{
    public TestRun $testRun;

    public function mount(TestRun $testRun): void
    {
        Gate::authorize('view', $testRun->project);
    }

    public function render()
    {
        $this->testRun->load(['project', 'user', 'checkResults.check']);
        $outcomes = collect($this->testRun->feature_outcomes ?? []);
        $features = $this->testRun->project->features()->whereKey($outcomes->pluck('feature_id'))->get()->keyBy('id');

        return view('livewire.test-run-detail', [
            'outcomes' => $outcomes,
            'features' => $features,
            'checkResults' => $this->testRun->checkResults->groupBy('feature_id'),
        ])->title('Test run #'.$this->testRun->id);
    }
}
