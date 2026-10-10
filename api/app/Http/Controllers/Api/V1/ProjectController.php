<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreProjectRequest;
use App\Http\Requests\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class ProjectController extends Controller
{
    /**
     * List accessible projects. ?archived=exclude (default) | include | only
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Project::class);

        $validated = $request->validate([
            'archived' => ['sometimes', Rule::in(['exclude', 'include', 'only'])],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ]);

        $projects = Project::query()
            ->accessibleBy($request->user())
            ->withProgressCounts()
            ->with('owner')
            ->when(($validated['archived'] ?? 'exclude') === 'exclude', fn ($q) => $q->whereNull('archived_at'))
            ->when(($validated['archived'] ?? null) === 'only', fn ($q) => $q->whereNotNull('archived_at'))
            ->latest('updated_at')
            ->paginate($validated['per_page'] ?? 25)
            ->withQueryString();

        return ProjectResource::collection($projects);
    }

    public function store(StoreProjectRequest $request): ProjectResource
    {
        Gate::authorize('create', Project::class);

        $project = $request->user()->ownedProjects()->create($request->validated());

        return new ProjectResource($project->load('owner'));
    }

    public function show(Project $project): ProjectResource
    {
        Gate::authorize('view', $project);

        return new ProjectResource($project->load('owner'));
    }

    public function update(UpdateProjectRequest $request, Project $project): ProjectResource
    {
        Gate::authorize('update', $project);

        $project->update($request->validated());

        return new ProjectResource($project->load('owner'));
    }

    public function archive(Project $project): ProjectResource
    {
        Gate::authorize('archive', $project);

        if (! $project->isArchived()) {
            $project->forceFill(['archived_at' => now()])->save();
        }

        return new ProjectResource($project->load('owner'));
    }

    public function unarchive(Project $project): ProjectResource
    {
        Gate::authorize('archive', $project);

        $project->forceFill(['archived_at' => null])->save();

        return new ProjectResource($project->load('owner'));
    }
}
