<?php

namespace App\Livewire;

use App\Models\Project;
use App\Support\AgentSetup;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * "Connect a coding agent" card: one click issues a coding-agent token limited to this project
 * and shows a prompt to paste into the agent, which then sets everything up itself.
 */
class ConnectAgent extends Component
{
    public Project $project;

    /** Show the full introduction (empty project) or a compact card (settings). */
    public bool $intro = false;

    /** The prompt just generated; it contains the token, so it is shown once. */
    public ?string $prompt = null;

    /** Which prompt was generated: "new" (just started), "existing" (already developed) or "join" (already tracked). */
    public ?string $stage = null;

    public function generate(string $stage): void
    {
        Gate::authorize('update', $this->project);
        abort_unless(in_array($stage, AgentSetup::STAGES, true), 422);

        $this->prompt = AgentSetup::issue($this->project, auth()->user(), $stage)['prompt'];
        $this->stage = $stage;
    }

    public function dismiss(): void
    {
        $this->reset('prompt', 'stage');
    }

    public function render()
    {
        Gate::authorize('view', $this->project);

        $tokens = auth()->user()->tokens()
            // abilities is a JSON array stored as text; the quotes keep project:1 from matching project:12
            ->where('abilities', 'like', '%"project:'.$this->project->id.'"%')
            ->where(fn ($q) => $q->whereNull('expires_at')->orWhere('expires_at', '>', now()))
            ->latest()
            ->get(['id', 'name', 'last_used_at', 'expires_at', 'created_at']);

        return view('livewire.connect-agent', [
            'tokens' => $tokens,
            // Once features are recorded, a new contributor joins instead of importing them again.
            'featuresCount' => $this->project->features()->count(),
            'days' => AgentSetup::TOKEN_DAYS,
        ]);
    }
}
