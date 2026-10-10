<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\TestRun;
use App\Services\VerificationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Automated test results. Submitting requires the `verify` ability, which the agent CLI
 * token does not have, so an agent cannot verify its own work.
 */
class TestRunController extends Controller
{
    public function index(Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $runs = $project->testRuns()->latest()->latest('id')->paginate(25);

        return response()->json([
            'data' => collect($runs->items())->map(fn (TestRun $r) => self::present($r)),
            'meta' => ['current_page' => $runs->currentPage(), 'last_page' => $runs->lastPage(), 'total' => $runs->total()],
        ]);
    }

    public function show(TestRun $testRun): JsonResponse
    {
        Gate::authorize('view', $testRun->project);

        return response()->json(['data' => self::present($testRun->load('checkResults.check'), detailed: true)]);
    }

    public function store(Request $request, Project $project, VerificationService $verification): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            'runner' => ['nullable', 'string', 'max:100'],
            'git_branch' => ['nullable', 'string', 'max:255'],
            'git_commit' => ['nullable', 'string', 'regex:/^[0-9a-f]{7,64}$/i'],
            'git_dirty' => ['boolean'],
            'cases' => ['required', 'array', 'min:1', 'max:20000'],
            'cases.*.id' => ['required', 'string', 'max:1000'],
            'cases.*.file' => ['nullable', 'string', 'max:1024'],
            'cases.*.outcome' => ['required', Rule::in(['passed', 'failed', 'error', 'skipped'])],
            'cases.*.message' => ['nullable', 'string', 'max:500'],
        ]);

        $run = $verification->record($project, $data, $request->user());

        return response()->json(['data' => self::present($run, detailed: true)], 201);
    }

    public static function present(TestRun $run, bool $detailed = false): array
    {
        $outcomes = collect($run->feature_outcomes ?? []);

        return [
            'id' => $run->id,
            'project_id' => $run->project_id,
            'runner' => $run->runner,
            'git' => ['branch' => $run->git_branch, 'commit' => $run->git_commit, 'dirty' => $run->git_dirty],
            'totals' => ['total' => $run->total, 'passed' => $run->passed, 'failed' => $run->failed, 'skipped' => $run->skipped],
            'features' => $outcomes->countBy('outcome')->all(),
            'feature_outcomes' => $detailed ? $outcomes->values()->all() : null,
            'check_results' => $detailed && $run->relationLoaded('checkResults') ? $run->checkResults->map(fn ($r) => [
                'check_id' => $r->feature_check_id,
                'feature_id' => $r->feature_id,
                'pattern' => $r->check?->pattern,
                'outcome' => $r->outcome,
                'matched' => $r->matched,
                'failed' => $r->failed,
                'failures' => $r->failures,
            ])->all() : null,
            'created_at' => $run->created_at,
        ];
    }
}
