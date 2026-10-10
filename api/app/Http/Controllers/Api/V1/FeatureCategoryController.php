<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Resources\FeatureCategoryResource;
use App\Models\FeatureCategory;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Category access follows the parent project's policy.
 */
class FeatureCategoryController extends Controller
{
    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return FeatureCategoryResource::collection($project->featureCategories()->withCount('features')->get());
    }

    public function store(Request $request, Project $project): FeatureCategoryResource
    {
        Gate::authorize('update', $project);

        $category = $project->featureCategories()->create($request->validate($this->rules($project)));

        return new FeatureCategoryResource($category);
    }

    public function update(Request $request, FeatureCategory $category): FeatureCategoryResource
    {
        Gate::authorize('update', $category->project);

        $category->update($request->validate($this->rules($category->project, $category, partial: true)));

        return new FeatureCategoryResource($category);
    }

    /**
     * Features in a deleted category become uncategorised; they are not deleted.
     */
    public function destroy(FeatureCategory $category): Response
    {
        Gate::authorize('update', $category->project);

        $category->delete();

        return response()->noContent();
    }

    private function rules(Project $project, ?FeatureCategory $ignore = null, bool $partial = false): array
    {
        return [
            'name' => [...($partial ? ['sometimes'] : []), 'required', 'string', 'max:255',
                Rule::unique('feature_categories')->where('project_id', $project->id)->ignore($ignore)],
            'description' => ['nullable', 'string', 'max:5000'],
            'position' => ['integer', 'min:0', 'max:65535'],
        ];
    }
}
