<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\Platform;
use App\Http\Controllers\Controller;
use App\Http\Resources\DevelopmentReportResource;
use App\Http\Resources\FeatureResource;
use App\Http\Resources\FeatureStatusChangeResource;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Services\ProjectDashboard;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectDashboardController extends Controller
{
    /**
     * ?platform=ios narrows the feature numbers and lists to that platform (tagged or untagged features).
     */
    public function __invoke(Request $request, Project $project, ProjectDashboard $dashboard): JsonResponse
    {
        Gate::authorize('view', $project);

        $filters = $request->validate(['platform' => [Rule::in(array_map(fn (Platform $p) => $p->value, $project->platformList()))]]);
        $dashboard = $dashboard->forPlatform(isset($filters['platform']) ? Platform::from($filters['platform']) : null);

        return response()->json(['data' => [
            'project' => new ProjectResource($project->load('owner')),
            'summary' => $dashboard->summary($project),
            'needs_attention' => FeatureResource::collection($dashboard->attention($project)),
            'recently_updated' => FeatureResource::collection($dashboard->recentlyUpdated($project)),
            'recent_changes' => FeatureStatusChangeResource::collection($dashboard->recentChanges($project)),
            'recent_reports' => DevelopmentReportResource::collection($dashboard->recentReports($project)),
            'unresolved' => $dashboard->unresolved($project),
            'without_checks' => FeatureResource::collection($dashboard->withoutChecks($project)),
            // Decided first: those are the agent's to-do. Then the ones waiting for a person.
            'mismatches' => $project->mismatches_enabled ? $project->specMismatches()->with('feature:id,name')->where('status', '!=', 'applied')
                ->orderByRaw("case status when 'decided' then 0 else 1 end")->latest('updated_at')->limit(20)->get()
                ->map(fn ($m) => SpecMismatchController::present($m)) : [],
            'open_tickets' => $dashboard->openTickets($project)->map(fn ($t) => TicketController::present($t)),
            'recent_feedback' => $dashboard->recentFeedback($project)->map(fn ($c) => TicketController::presentComment($c) + [
                'ticket' => ['id' => $c->ticket->id, 'reference' => $c->ticket->reference, 'title' => $c->ticket->title, 'status' => $c->ticket->status],
            ]),
        ]]);
    }
}
