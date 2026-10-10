<?php

namespace App\Services;

use App\Models\AgentRun;
use App\Models\Project;
use App\Models\UnresolvedItem;
use App\Models\User;
use App\Support\Cli;
use App\Support\DiagramPrompt;
use App\Support\FeaturePrompt;
use App\Support\MismatchPrompt;
use App\Support\NextSteps;
use App\Support\PhasePrompt;
use App\Support\UnresolvedPrompt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * "Proceed": queues a prompt for the project's coding agent, which `unseen runner` on the developer's
 * computer picks up and opens in Claude Code. The prompt is always built here from what was clicked
 * (kind + id within the project); a browser can't send its own prompt text.
 */
class AgentRunService
{
    public const KINDS = ['feature', 'phase', 'item', 'items', 'sort', 'find_mismatches', 'apply_mismatches', 'diagrams', 'suggestions', 'ci', 'tests', 'update_cli'];

    public function queue(Project $project, User $user, string $kind, ?int $ref = null): AgentRun
    {
        [$title, $prompt] = $this->build($project, $kind, $ref);

        return $project->agentRuns()->forceCreate([
            'project_id' => $project->id,
            'user_id' => $user->id,
            'kind' => $kind,
            'ref' => $ref,
            'title' => Str::limit($title, 250),
            'prompt' => $prompt,
            'status' => AgentRun::QUEUED,
        ]);
    }

    /**
     * Hands the oldest waiting request to a runner (or null) and records that the runner checked in.
     * Requests older than the expiry window are expired rather than launched late.
     */
    /**
     * A runner only picks up its own person's requests: "Proceed" opens Claude Code on the computer of
     * whoever clicked it, never on another member's.
     */
    public function claim(Project $project, User $user, string $runnerName, ?string $folder = null): ?AgentRun
    {
        return DB::transaction(function () use ($project, $user, $runnerName, $folder) {
            $workspace = $project->workspaceFor($user);
            $workspace->fill(['runner_seen_at' => now(), 'runner_name' => $runnerName]);
            if (! $workspace->local_path && $folder) {
                $workspace->local_path = $folder; // a runner started in the linked repo: no need to set it by hand
            }
            $workspace->save();

            $project->agentRuns()->where('status', AgentRun::QUEUED)
                ->where('created_at', '<', now()->subMinutes(AgentRun::EXPIRES_AFTER_MINUTES))
                ->update(['status' => AgentRun::EXPIRED, 'finished_at' => now()]);

            $run = $project->agentRuns()->where('status', AgentRun::QUEUED)->where('user_id', $user->id)
                ->orderBy('id')->lockForUpdate()->first();
            $run?->update(['status' => AgentRun::CLAIMED, 'runner_name' => $runnerName, 'claimed_at' => now()]);

            return $run;
        });
    }

    /**
     * @return array{0: string, 1: string} title and prompt
     */
    private function build(Project $project, string $kind, ?int $ref): array
    {
        return match ($kind) {
            'feature' => (function () use ($project, $ref) {
                $feature = $project->features()->findOrFail($ref);
                $next = FeaturePrompt::build($feature);

                return ["{$next['label']}: {$feature->name}", $next['prompt']];
            })(),
            'diagrams' => (function () use ($project, $ref) {
                $feature = $project->features()->findOrFail($ref);
                $sync = DiagramPrompt::build($feature);

                return ["{$sync['label']}: {$feature->name} diagrams", $sync['prompt']];
            })(),
            'phase' => (function () use ($project, $ref) {
                $phase = $project->roadmapPhases()->findOrFail($ref);
                $next = PhasePrompt::build($phase);

                return ["{$next['label']}: {$phase->key} {$phase->name}", $next['prompt']];
            })(),
            'item' => (function () use ($project, $ref) {
                $item = $project->unresolvedItems()->where('status', UnresolvedItem::OPEN)->findOrFail($ref);

                return ['Resolve: '.Str::limit($item->text, 120), UnresolvedPrompt::forItem($item)];
            })(),
            'items' => (function () use ($project) {
                $open = $project->unresolvedItems()->where('status', UnresolvedItem::OPEN)->latest()->latest('id')->get();
                abort_if($open->isEmpty(), 404);

                return ["Work through {$open->count()} open items", UnresolvedPrompt::forAll($project, $open, $open->count())];
            })(),
            'sort' => (function () use ($project) {
                $open = $project->unresolvedItems()->where('status', UnresolvedItem::OPEN)->latest()->latest('id')->get();
                abort_if($open->isEmpty(), 404);

                return ['Sort open items by who has to act', UnresolvedPrompt::forSorting($project, $open, $open->count())];
            })(),
            'find_mismatches' => ['Find PRD vs design mismatches', $project->mismatches_enabled ? MismatchPrompt::find($project) : abort(404)],
            'apply_mismatches' => ['Apply PRD vs design decisions', ($project->mismatches_enabled ? MismatchPrompt::apply($project) : null) ?? abort(404)],
            'suggestions' => ['Review scan suggestions', NextSteps::prompt($project, 'suggestions')],
            'ci' => ['Set up CI verification', NextSteps::prompt($project, 'ci')],
            'tests' => ['Link tests to implemented features', NextSteps::prompt($project, 'tests')],
            'update_cli' => ['Update the Unseen CLI to '.Cli::LATEST, Cli::updatePrompt()],
            default => throw new InvalidArgumentException("Unknown run kind [{$kind}]."),
        };
    }
}
