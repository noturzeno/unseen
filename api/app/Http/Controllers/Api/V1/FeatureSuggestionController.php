<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FeatureStatus;
use App\Enums\SuggestionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\FeatureResource;
use App\Http\Resources\FeatureSuggestionResource;
use App\Models\Feature;
use App\Models\FeatureSuggestion;
use App\Models\Project;
use App\Services\SuggestionReviewService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Review of scan suggestions. Every action here is a human decision, recorded with reviewer and time.
 */
class FeatureSuggestionController extends Controller
{
    public function __construct(private readonly SuggestionReviewService $reviews) {}

    public function index(Request $request, Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        $filters = $request->validate([
            'status' => [Rule::enum(SuggestionStatus::class)],
            'per_page' => ['integer', 'min:1', 'max:100'],
        ]);

        return FeatureSuggestionResource::collection($project->featureSuggestions()
            ->with(['feature', 'matchedFeature'])
            ->where('status', $filters['status'] ?? SuggestionStatus::Pending->value)
            ->orderBy('name')
            ->paginate($filters['per_page'] ?? 50)
            ->withQueryString());
    }

    public function show(FeatureSuggestion $suggestion): FeatureSuggestionResource
    {
        Gate::authorize('view', $suggestion->project);

        return new FeatureSuggestionResource($suggestion->load(['feature', 'matchedFeature']));
    }

    public function accept(Request $request, FeatureSuggestion $suggestion): FeatureResource
    {
        Gate::authorize('update', $suggestion->project);

        $edits = $request->validate([
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:10000'],
            'category_id' => ['nullable', 'integer', Rule::exists('feature_categories', 'id')->where('project_id', $suggestion->project_id)],
            'status' => [Rule::enum(FeatureStatus::class)->only(FeatureStatus::agentSettable())],
        ]);

        $feature = $this->reviews->accept($suggestion, $edits, $request->user());

        return (new FeatureResource($feature->load(['category', 'acceptanceCriteria'])))->additional(['suggestion_id' => $suggestion->id]);
    }

    public function merge(Request $request, FeatureSuggestion $suggestion): FeatureSuggestionResource
    {
        Gate::authorize('update', $suggestion->project);

        $data = $request->validate([
            'feature_id' => ['required', 'integer', Rule::exists('features', 'id')->where('project_id', $suggestion->project_id)],
        ]);

        $this->reviews->merge($suggestion, Feature::findOrFail($data['feature_id']), $request->user());

        return new FeatureSuggestionResource($suggestion->load(['feature', 'matchedFeature']));
    }

    public function dismiss(Request $request, FeatureSuggestion $suggestion): FeatureSuggestionResource
    {
        Gate::authorize('update', $suggestion->project);

        $this->reviews->dismiss($suggestion, $request->user());

        return new FeatureSuggestionResource($suggestion);
    }

    public function restore(FeatureSuggestion $suggestion): FeatureSuggestionResource
    {
        Gate::authorize('update', $suggestion->project);

        $this->reviews->restore($suggestion);

        return new FeatureSuggestionResource($suggestion);
    }
}
