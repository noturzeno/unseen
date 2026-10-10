<?php

namespace App\Services;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\FeatureCheck;
use App\Models\Project;
use App\Models\TestRun;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Applies the verification policy to a submitted automated test run.
 *
 * Check outcome (one check = one pattern selecting test cases):
 *   failed  - at least one matching case failed or errored
 *   passed  - at least one matching case passed and none failed
 *   missing - no matching case ran (skipped cases do not count as run)
 *
 * Feature outcome (only features that have checks):
 *   not_covered - none of its checks matched anything in this run      -> no change
 *   failed      - any check failed                                     -> verification `failed`
 *   incomplete  - no failures, but some check is missing               -> no change
 *   verified    - every check passed, status is `implemented`, and the run
 *                 is tied to a commit with no uncommitted changes       -> verification `verified`
 *   passed_not_applied - every check passed but a condition above
 *                 is not met (reason recorded)                         -> no change
 *
 * Changes are recorded with source `test_verified`. Features without checks are never
 * verified automatically; the dashboard lists them as having no automated checks.
 */
class VerificationService
{
    private const MAX_FAILURES_PER_CHECK = 5;

    public function __construct(private readonly FeatureStatusService $statuses) {}

    /**
     * @param  array{runner?: ?string, git_branch?: ?string, git_commit?: ?string, git_dirty?: bool,
     *     cases: list<array{id: string, file?: ?string, outcome: string, message?: ?string}>}  $data
     */
    public function record(Project $project, array $data, ?User $user): TestRun
    {
        $cases = collect($data['cases']);

        return DB::transaction(function () use ($project, $data, $user, $cases) {
            $run = $project->testRuns()->create([
                'user_id' => $user?->id,
                'runner' => $data['runner'] ?? null,
                'git_branch' => $data['git_branch'] ?? null,
                'git_commit' => $data['git_commit'] ?? null,
                'git_dirty' => (bool) ($data['git_dirty'] ?? false),
                'total' => $cases->count(),
                'passed' => $cases->where('outcome', 'passed')->count(),
                'failed' => $cases->whereIn('outcome', ['failed', 'error'])->count(),
                'skipped' => $cases->where('outcome', 'skipped')->count(),
            ]);

            $features = $project->features()->has('checks')->with('checks')->lockForUpdate()->get();
            $outcomes = $features->map(fn (Feature $feature) => $this->evaluate($run, $feature, $cases, $user))->values()->all();

            $run->update(['feature_outcomes' => $outcomes]);

            return $run;
        });
    }

    /**
     * @return array{feature_id: int, outcome: string, reason: ?string}
     */
    private function evaluate(TestRun $run, Feature $feature, Collection $cases, ?User $user): array
    {
        $checkOutcomes = $feature->checks->map(function (FeatureCheck $check) use ($run, $feature, $cases) {
            $matched = $cases->filter(fn ($c) => $check->matches($c['id'], $c['file'] ?? null));
            $failed = $matched->whereIn('outcome', ['failed', 'error']);
            $passed = $matched->where('outcome', 'passed');
            $outcome = $failed->isNotEmpty() ? 'failed' : ($passed->isNotEmpty() ? 'passed' : 'missing');

            $run->checkResults()->create([
                'feature_check_id' => $check->id,
                'feature_id' => $feature->id,
                'outcome' => $outcome,
                'matched' => $matched->count(),
                'failed' => $failed->count(),
                'failures' => $failed->take(self::MAX_FAILURES_PER_CHECK)
                    ->map(fn ($c) => ['id' => $c['id'], 'message' => $c['message'] ?? null])->values()->all() ?: null,
            ]);

            return ['outcome' => $outcome, 'ran' => $matched->where('outcome', '!=', 'skipped')->count()];
        });

        if ($checkOutcomes->every(fn ($c) => $c['ran'] === 0)) {
            return $this->outcome($feature, 'not_covered', 'None of its checks ran in this test run.');
        }

        $label = $run->git_commit ? 'test run #'.$run->id.' at '.substr($run->git_commit, 0, 10) : 'test run #'.$run->id;

        if ($checkOutcomes->contains('outcome', 'failed')) {
            $failedCount = $checkOutcomes->where('outcome', 'failed')->count();
            $this->setVerification($feature, VerificationStatus::Failed, $user, "{$failedCount} of {$checkOutcomes->count()} checks failed in {$label}.");

            return $this->outcome($feature, 'failed', null);
        }

        if ($checkOutcomes->contains('outcome', 'missing')) {
            return $this->outcome($feature, 'incomplete', 'Some checks had no passing tests in this run.');
        }

        $blocker = match (true) {
            $feature->status !== FeatureStatus::Implemented => 'The feature is not marked implemented.',
            ! $run->git_commit => 'The run is not tied to a git commit.',
            $run->git_dirty => 'The run included uncommitted changes.',
            default => null,
        };

        if ($blocker) {
            return $this->outcome($feature, 'passed_not_applied', $blocker);
        }

        $this->setVerification($feature, VerificationStatus::Verified, $user, "All {$checkOutcomes->count()} checks passed in {$label}.");

        return $this->outcome($feature, 'verified', null);
    }

    private function setVerification(Feature $feature, VerificationStatus $status, ?User $user, string $note): void
    {
        if ($feature->verification_status !== $status) {
            $this->statuses->apply($feature, null, $status, ClaimSource::TestVerified, $user, $note);
        }
    }

    private function outcome(Feature $feature, string $outcome, ?string $reason): array
    {
        return ['feature_id' => $feature->id, 'outcome' => $outcome, 'reason' => $reason];
    }
}
