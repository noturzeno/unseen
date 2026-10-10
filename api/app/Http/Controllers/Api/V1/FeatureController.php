<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Enums\Platform;
use App\Enums\VerificationStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreFeatureRequest;
use App\Http\Requests\UpdateFeatureRequest;
use App\Http\Resources\FeatureResource;
use App\Http\Resources\FeatureStatusChangeResource;
use App\Models\Feature;
use App\Models\Project;
use App\Services\FeatureStatusService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Feature access follows the parent project's policy. Features are not deleted
 * through the API so the history trail is kept; use status `not_implemented`.
 */
class FeatureController extends Controller
{
    public function __construct(private readonly FeatureStatusService $statuses) {}

    /**
     * Filters: status, verification_status, category_id (id or "none"), platform (tagged with it or untagged).
     */
    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        $filters = $request->validate([
            'status' => [Rule::enum(FeatureStatus::class)],
            'verification_status' => [Rule::enum(VerificationStatus::class)],
            'category_id' => ['regex:/^(none|\d+)$/'],
            'platform' => [Rule::enum(Platform::class)],
            'per_page' => ['integer', 'min:1', 'max:100'],
        ]);

        $features = $project->features()
            ->with(['category', 'acceptanceCriteria'])
            ->when($filters['status'] ?? null, fn ($q, $v) => $q->where('status', $v))
            ->when($filters['verification_status'] ?? null, fn ($q, $v) => $q->where('verification_status', $v))
            ->when(($filters['category_id'] ?? null) === 'none', fn ($q) => $q->whereNull('category_id'))
            ->when(ctype_digit($filters['category_id'] ?? ''), fn ($q) => $q->where('category_id', (int) $filters['category_id']))
            ->when($filters['platform'] ?? null, fn ($q, $v) => $q->onPlatform(Platform::from($v)))
            ->latest('updated_at')
            ->latest('id')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString();

        return FeatureResource::collection($features);
    }

    public function store(StoreFeatureRequest $request, Project $project): FeatureResource
    {
        Gate::authorize('update', $project);

        $data = $request->validated();

        $feature = DB::transaction(function () use ($data, $project, $request) {
            $feature = $project->features()->make(collect($data)->only(['name', 'description', 'category_id', 'platforms'])->all());

            $this->statuses->apply(
                $feature,
                isset($data['status']) ? FeatureStatus::from($data['status']) : null,
                isset($data['verification_status']) ? VerificationStatus::from($data['verification_status']) : null,
                ClaimSource::AgentReported,
                $request->user(),
                $data['status_note'] ?? null,
            );

            $this->syncCriteria($feature, $data['acceptance_criteria'] ?? []);

            return $feature;
        });

        return new FeatureResource($feature->load(['category', 'acceptanceCriteria']));
    }

    public function show(Feature $feature): FeatureResource
    {
        Gate::authorize('view', $feature->project);

        return new FeatureResource($feature->load(['category', 'acceptanceCriteria']));
    }

    /**
     * Partial update. `acceptance_criteria`, when present, replaces the full list.
     */
    public function update(UpdateFeatureRequest $request, Feature $feature): FeatureResource
    {
        Gate::authorize('update', $feature->project);

        $data = $request->validated();

        DB::transaction(function () use ($data, $feature, $request) {
            $feature->fill(collect($data)->only(['name', 'description', 'category_id', 'platforms'])->all());

            if (isset($data['status']) || isset($data['verification_status'])) {
                $this->statuses->apply(
                    $feature,
                    isset($data['status']) ? FeatureStatus::from($data['status']) : null,
                    isset($data['verification_status']) ? VerificationStatus::from($data['verification_status']) : null,
                    ClaimSource::AgentReported,
                    $request->user(),
                    $data['status_note'] ?? null,
                );
            } else {
                $feature->save();
            }

            if (array_key_exists('acceptance_criteria', $data)) {
                $feature->acceptanceCriteria()->delete();
                $this->syncCriteria($feature, $data['acceptance_criteria']);
                $feature->touch();
            }
        });

        return new FeatureResource($feature->load(['category', 'acceptanceCriteria']));
    }

    public function history(Feature $feature): AnonymousResourceCollection
    {
        Gate::authorize('view', $feature->project);

        return FeatureStatusChangeResource::collection($feature->statusHistory()->with('user')->paginate(50));
    }

    /**
     * @param  list<string>  $criteria
     */
    private function syncCriteria(Feature $feature, array $criteria): void
    {
        foreach (array_values($criteria) as $position => $description) {
            $feature->acceptanceCriteria()->create([
                'description' => $description,
                'position' => $position,
                'source' => ClaimSource::AgentReported,
            ]);
        }
    }
}
