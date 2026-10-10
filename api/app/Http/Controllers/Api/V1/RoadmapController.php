<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClaimSource;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\RoadmapPhase;
use App\Services\RoadmapService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

/**
 * The project's roadmap: phases and their implementation lists. Coding agents replace it in full
 * (PUT) whenever the plan in the repository changes; it is recorded as agent-reported.
 */
class RoadmapController extends Controller
{
    public function __construct(private readonly RoadmapService $roadmap) {}

    public function show(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        return self::present($project, $this->roadmap->phases($project));
    }

    public function sync(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate(RoadmapService::rules($project));
        $phases = $this->roadmap->sync($project, $data['phases'], ClaimSource::AgentReported);

        return self::present($project->refresh(), $phases);
    }

    /**
     * @param  Collection<int, RoadmapPhase>  $phases
     */
    private static function present(Project $project, Collection $phases): JsonResponse
    {
        return response()->json(['data' => [
            'synced_at' => $project->roadmap_synced_at,
            'phases' => $phases->map(fn (RoadmapPhase $phase) => [
                'id' => $phase->id,
                'key' => $phase->key,
                'name' => $phase->name,
                'description' => $phase->description,
                'status' => $phase->status,
                'target_date' => $phase->target_date?->toDateString(),
                'progress' => RoadmapService::progress($phase),
                'items' => $phase->items->map(fn ($item) => [
                    'text' => $item->text,
                    'done' => $item->done,
                    'feature' => $item->feature ? [
                        'id' => $item->feature->id,
                        'name' => $item->feature->name,
                        'status' => $item->feature->status,
                        'verification_status' => $item->feature->verification_status,
                    ] : null,
                ])->values(),
            ])->values(),
        ]]);
    }
}
