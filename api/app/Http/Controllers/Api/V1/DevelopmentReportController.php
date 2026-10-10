<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClaimSource;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreDevelopmentReportRequest;
use App\Http\Resources\DevelopmentReportResource;
use App\Http\Resources\FeatureEvidenceResource;
use App\Models\DevelopmentReport;
use App\Models\Feature;
use App\Models\Project;
use App\Services\DevelopmentReportService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Reports are append-only: there is no update or delete, so the audit trail is kept.
 * Reports submitted through the API are recorded as `agent_reported`.
 */
class DevelopmentReportController extends Controller
{
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        $filters = $request->validate([
            'feature_id' => ['integer', Rule::exists('features', 'id')->where('project_id', $project->id)],
            'per_page' => ['integer', 'min:1', 'max:100'],
        ]);

        $reports = $project->developmentReports()
            ->with(['user', 'features'])
            ->when($filters['feature_id'] ?? null, fn ($q, $id) => $q->whereHas('features', fn ($f) => $f->whereKey($id)))
            ->latest('reported_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 25)
            ->withQueryString();

        return DevelopmentReportResource::collection($reports);
    }

    public function store(StoreDevelopmentReportRequest $request, Project $project, DevelopmentReportService $reports): DevelopmentReportResource
    {
        Gate::authorize('update', $project);

        $report = $reports->record($project, $request->validated(), ClaimSource::AgentReported, $request->user());

        return new DevelopmentReportResource($report->load(['user', 'features', 'ticketUpdates.ticket']));
    }

    public function show(DevelopmentReport $report): DevelopmentReportResource
    {
        Gate::authorize('view', $report->project);

        return new DevelopmentReportResource($report->load(['user', 'features', 'ticketUpdates.ticket']));
    }

    public function evidence(Feature $feature): AnonymousResourceCollection
    {
        Gate::authorize('view', $feature->project);

        return FeatureEvidenceResource::collection($feature->evidence()->with('developmentReport')->paginate(50));
    }
}
