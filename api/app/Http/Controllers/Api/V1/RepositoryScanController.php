<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\FeatureStatus;
use App\Enums\SuggestionStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\RepositoryScanResource;
use App\Models\Project;
use App\Services\RepositoryScanService;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class RepositoryScanController extends Controller
{
    public const EVIDENCE_TYPES = ['route', 'controller', 'model', 'migration', 'page', 'component', 'test', 'doc', 'other'];

    public function index(Project $project): AnonymousResourceCollection
    {
        Gate::authorize('view', $project);

        return RepositoryScanResource::collection($project->repositoryScans()->with('user')->latest()->latest('id')->paginate(25));
    }

    public function store(Request $request, Project $project, RepositoryScanService $scans): RepositoryScanResource
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'scanner' => ['nullable', 'string', 'max:100'],
            'git_branch' => ['nullable', 'string', 'max:255'],
            'git_commit' => ['nullable', 'string', 'regex:/^[0-9a-f]{7,64}$/i'],
            'candidates' => ['present', 'array', 'max:500'],
            'candidates.*.key' => ['required', 'string', 'max:255'],
            'candidates.*.name' => ['required', 'string', 'max:255'],
            'candidates.*.description' => ['nullable', 'string', 'max:2000'],
            'candidates.*.category' => ['nullable', 'string', 'max:255'],
            // What the project's docs say (e.g. an implementation matrix row); a default, not verification.
            'candidates.*.status' => ['nullable', Rule::enum(FeatureStatus::class)->only(FeatureStatus::agentSettable())],
            'candidates.*.evidence' => ['required', 'array', 'min:1', 'max:50'],
            'candidates.*.evidence.*.type' => ['required', Rule::in(self::EVIDENCE_TYPES)],
            'candidates.*.evidence.*.path' => ['required', 'string', 'max:1024'],
            'candidates.*.evidence.*.detail' => ['nullable', 'string', 'max:500'],
            'candidates.*.evidence.*.line' => ['nullable', 'integer', 'min:1'],
        ]);

        // Keep only documented keys inside list items.
        $data['candidates'] = array_map(fn ($c) => [
            ...array_intersect_key($c, array_flip(['key', 'name', 'description', 'category', 'status'])),
            'evidence' => array_map(fn ($e) => array_intersect_key($e, array_flip(['type', 'path', 'detail', 'line'])), $c['evidence']),
        ], $data['candidates']);

        $scan = $scans->record($project, $data, $request->user());
        $scan->setAttribute('pending_review', $project->featureSuggestions()->where('status', SuggestionStatus::Pending)->count());

        return new RepositoryScanResource($scan->load('user'));
    }
}
