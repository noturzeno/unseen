<?php

namespace App\Livewire;

use App\Models\AgentRun;
use App\Models\Project;
use App\Services\AgentRunService;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Component;

/**
 * "Proceed": sends one of Unseen's prompts to `unseen runner` on the developer's computer, which
 * opens Claude Code with it in the project folder. Shows the run's progress until Claude Code opens.
 */
class ProceedButton extends Component
{
    #[Locked]
    public Project $project;

    #[Locked]
    public string $kind;

    #[Locked]
    public ?int $ref = null;

    #[Locked]
    public bool $small = false;

    /** Button text instead of "Proceed", e.g. "Sync with code". */
    #[Locked]
    public ?string $label = null;

    #[Locked]
    public ?int $runId = null;

    public function proceed(AgentRunService $runs): void
    {
        Gate::authorize('update', $this->project);
        abort_unless(in_array($this->kind, AgentRunService::KINDS, true), 422);

        $this->runId = $runs->queue($this->project, auth()->user(), $this->kind, $this->ref)->id;
    }

    public function render()
    {
        $run = $this->runId ? $this->project->agentRuns()->find($this->runId) : null;
        $waiting = $run && in_array($run->status, [AgentRun::QUEUED, AgentRun::CLAIMED], true);

        return view('livewire.proceed-button', [
            'workspace' => $workspace = $this->project->workspaceFor(auth()->user()),
            'localPath' => $workspace->local_path,
            'run' => $run,
            'waiting' => $waiting,
            // Nobody has picked it up for a while: most likely no runner is running.
            'stale' => $waiting && $run->status === AgentRun::QUEUED && $run->created_at->lt(now()->subSeconds(15)),
        ]);
    }
}
