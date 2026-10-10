<?php

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use App\Models\Feature;
use App\Models\FeatureCheck;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Checks link a feature to the automated tests that prove it. Managing them needs `write`.
 */
class FeatureCheckController extends Controller
{
    public function index(Feature $feature): JsonResponse
    {
        Gate::authorize('view', $feature->project);

        return response()->json(['data' => $feature->checks()->with('latestResult.testRun')->get()->map(fn (FeatureCheck $c) => self::present($c))]);
    }

    public function store(Request $request, Feature $feature): JsonResponse
    {
        Gate::authorize('update', $feature->project);

        $data = $request->validate([
            'pattern' => ['required', 'string', 'max:500', Rule::unique('feature_checks')->where('feature_id', $feature->id)],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        $check = $feature->checks()->create([...$data, 'created_by' => $request->user()->id]);

        return response()->json(['data' => self::present($check)], 201);
    }

    public function destroy(FeatureCheck $check): Response
    {
        Gate::authorize('update', $check->feature->project);

        $check->delete();

        return response()->noContent();
    }

    public static function present(FeatureCheck $check): array
    {
        $result = $check->relationLoaded('latestResult') ? $check->latestResult : null;

        return [
            'id' => $check->id,
            'feature_id' => $check->feature_id,
            'pattern' => $check->pattern,
            'description' => $check->description,
            'latest_result' => $result ? [
                'outcome' => $result->outcome,
                'matched' => $result->matched,
                'failed' => $result->failed,
                'failures' => $result->failures,
                'test_run_id' => $result->test_run_id,
                'git_commit' => $result->testRun?->git_commit,
                'at' => $result->created_at,
            ] : null,
        ];
    }
}
