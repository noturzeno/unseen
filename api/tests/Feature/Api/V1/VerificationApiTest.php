<?php

namespace Tests\Feature\Api\V1;

use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus as V;
use App\Models\Feature;
use App\Models\FeatureCheck;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class VerificationApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->project = Project::factory()->for($this->user, 'owner')->create();
    }

    private function feature(array $patterns, FeatureStatus $status = FeatureStatus::Implemented, V $verification = V::NeedsVerification): Feature
    {
        $feature = Feature::factory()->for($this->project)->withStatus($status, $verification)->create();
        foreach ($patterns as $pattern) {
            $feature->checks()->create(['pattern' => $pattern]);
        }

        return $feature;
    }

    private function submitRun(array $cases, array $extra = [])
    {
        return $this->postJson("/api/v1/projects/{$this->project->id}/test-runs", [
            'runner' => 'unseen-cli 0.1.0',
            'git_branch' => 'main',
            'git_commit' => 'abcdef1234',
            'cases' => $cases,
            ...$extra,
        ]);
    }

    private static function case(string $id, string $outcome = 'passed', ?string $file = null): array
    {
        return ['id' => $id, 'outcome' => $outcome, 'file' => $file];
    }

    // --- Check matching ------------------------------------------------------

    public function test_pattern_matching(): void
    {
        $check = new FeatureCheck(['pattern' => 'Tests\\Feature\\InvoiceTest']);
        $this->assertTrue($check->matches('Tests\\Feature\\InvoiceTest::test_list'));
        $this->assertTrue($check->matches('Tests\\Feature\\InvoiceTest'));
        $this->assertFalse($check->matches('Tests\\Feature\\InvoiceTestExtra::test_x'));

        $glob = new FeatureCheck(['pattern' => '*InvoiceTest::test_export*']);
        $this->assertTrue($glob->matches('Tests\\Feature\\InvoiceTest::test_export_csv'));
        $this->assertFalse($glob->matches('Tests\\Feature\\InvoiceTest::test_list'));

        $file = new FeatureCheck(['pattern' => 'tests/invoice.test.ts']);
        $this->assertTrue($file->matches('invoices > lists', 'web/tests/invoice.test.ts'));
        $this->assertFalse($file->matches('invoices > lists', 'web/tests/other.test.ts'));
    }

    // --- Policy --------------------------------------------------------------

    public function test_all_checks_passing_verifies_an_implemented_feature(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest', 'Tests\\Browser\\InvoicePageTest']);

        $this->submitRun([
            self::case('Tests\\Feature\\InvoiceTest::test_list'),
            self::case('Tests\\Feature\\InvoiceTest::test_create'),
            self::case('Tests\\Browser\\InvoicePageTest::test_renders'),
            self::case('Tests\\Feature\\OtherTest::test_x', 'failed'),
        ])->assertCreated()
            ->assertJsonPath('data.totals', ['total' => 4, 'passed' => 3, 'failed' => 1, 'skipped' => 0])
            ->assertJsonPath('data.features', ['verified' => 1])
            ->assertJsonPath('data.feature_outcomes.0.outcome', 'verified');

        $this->assertSame(V::Verified, $feature->fresh()->verification_status);
        $this->assertDatabaseHas('feature_status_history', [
            'feature_id' => $feature->id, 'field' => 'verification_status', 'to_value' => 'verified', 'source' => 'test_verified',
            'note' => 'All 2 checks passed in test run #1 at abcdef1234.',
        ]);
    }

    public function test_any_failing_check_marks_the_feature_failed(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest'], FeatureStatus::Implemented, V::Verified);

        $this->submitRun([
            self::case('Tests\\Feature\\InvoiceTest::test_list'),
            ['id' => 'Tests\\Feature\\InvoiceTest::test_total', 'outcome' => 'failed', 'message' => 'Expected 10, got 9'],
        ])->assertCreated()->assertJsonPath('data.features', ['failed' => 1]);

        $this->assertSame(V::Failed, $feature->fresh()->verification_status);
        $this->assertDatabaseHas('test_run_check_results', ['feature_id' => $feature->id, 'outcome' => 'failed', 'matched' => 2, 'failed' => 1]);

        $this->getJson("/api/v1/features/{$feature->id}/checks")
            ->assertOk()
            ->assertJsonPath('data.0.latest_result.outcome', 'failed')
            ->assertJsonPath('data.0.latest_result.failures.0.message', 'Expected 10, got 9');
    }

    public function test_errors_count_as_failures(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest']);

        $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list', 'error')]);

        $this->assertSame(V::Failed, $feature->fresh()->verification_status);
    }

    public function test_missing_check_leaves_feature_unchanged(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest', 'Tests\\Feature\\InvoiceExportTest']);

        $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list')])
            ->assertJsonPath('data.feature_outcomes.0.outcome', 'incomplete');

        $this->assertSame(V::NeedsVerification, $feature->fresh()->verification_status);
    }

    public function test_skipped_tests_do_not_count_as_passing(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest']);

        $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list', 'skipped'), self::case('Tests\\Other::x')])
            ->assertJsonPath('data.feature_outcomes.0.outcome', 'not_covered');

        $this->assertSame(V::NeedsVerification, $feature->fresh()->verification_status);
    }

    public function test_features_not_covered_by_a_partial_run_are_untouched(): void
    {
        $verified = $this->feature(['Tests\\Feature\\LoginTest'], FeatureStatus::Implemented, V::Verified);
        $this->feature(['Tests\\Feature\\InvoiceTest']);

        $features = $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list')])->json('data.features');
        $this->assertEqualsCanonicalizing(['not_covered' => 1, 'verified' => 1], $features);

        $this->assertSame(V::Verified, $verified->fresh()->verification_status);
    }

    public function test_passing_checks_do_not_verify_unimplemented_features(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest'], FeatureStatus::InProgress, V::NotChecked);

        $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list')])
            ->assertJsonPath('data.feature_outcomes.0.outcome', 'passed_not_applied')
            ->assertJsonPath('data.feature_outcomes.0.reason', 'The feature is not marked implemented.');

        $this->assertSame(V::NotChecked, $feature->fresh()->verification_status);
    }

    public function test_runs_with_uncommitted_changes_or_no_commit_cannot_verify(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest']);

        $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list')], ['git_dirty' => true])
            ->assertJsonPath('data.feature_outcomes.0.reason', 'The run included uncommitted changes.');
        $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list')], ['git_commit' => null])
            ->assertJsonPath('data.feature_outcomes.0.reason', 'The run is not tied to a git commit.');

        $this->assertSame(V::NeedsVerification, $feature->fresh()->verification_status);
    }

    public function test_dirty_runs_can_still_mark_failures(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest'], FeatureStatus::Implemented, V::Verified);

        $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list', 'failed')], ['git_dirty' => true]);

        $this->assertSame(V::Failed, $feature->fresh()->verification_status);
    }

    public function test_features_without_checks_are_never_auto_verified(): void
    {
        $feature = Feature::factory()->for($this->project)->withStatus(FeatureStatus::Implemented, V::NeedsVerification)->create(['name' => 'Invoice']);

        $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list')])->assertJsonPath('data.features', []);

        $this->assertSame(V::NeedsVerification, $feature->fresh()->verification_status);
        $this->getJson("/api/v1/projects/{$this->project->id}/dashboard")
            ->assertJsonPath('data.summary.implemented_without_checks', 1)
            ->assertJsonPath('data.without_checks.0.id', $feature->id)
            ->assertJsonPath('data.summary.latest_test_run.totals.passed', 1);
    }

    public function test_already_verified_feature_gets_no_duplicate_history(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest'], FeatureStatus::Implemented, V::Verified);

        $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list')])->assertJsonPath('data.features', ['verified' => 1]);

        $this->assertDatabaseCount('feature_status_history', 0);
    }

    public function test_agent_report_after_verification_still_resets_it(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest']);
        $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list')]);
        $this->assertSame(V::Verified, $feature->fresh()->verification_status);

        $this->postJson("/api/v1/projects/{$this->project->id}/reports", ['summary' => 'Changed invoices', 'features' => [['id' => $feature->id]]])->assertCreated();

        $this->assertSame(V::NeedsVerification, $feature->fresh()->verification_status);
    }

    // --- Validation & access --------------------------------------------------

    public function test_payload_validation(): void
    {
        $this->submitRun([['id' => '', 'outcome' => 'passed'], ['id' => 'x', 'outcome' => 'flaky']], ['git_commit' => 'nope'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['cases.0.id', 'cases.1.outcome', 'git_commit']);
        $this->submitRun([])->assertUnprocessable()->assertJsonValidationErrors('cases');
        $this->assertDatabaseCount('test_runs', 0);
    }

    public function test_agent_cli_token_cannot_submit_test_runs(): void
    {
        $feature = $this->feature(['Tests\\Feature\\InvoiceTest']);
        $this->app['auth']->forgetGuards();
        $agent = $this->user->createToken('cli', ['read', 'report'])->plainTextToken;
        $ci = $this->user->createToken('ci', ['read', 'verify'])->plainTextToken;
        $payload = ['git_commit' => 'abcdef1', 'cases' => [self::case('Tests\\Feature\\InvoiceTest::test_list')]];

        $this->withToken($agent)->postJson("/api/v1/projects/{$this->project->id}/test-runs", $payload)->assertForbidden();
        $this->assertSame(V::NeedsVerification, $feature->fresh()->verification_status);

        $this->app['auth']->forgetGuards();
        $this->withToken($ci)->postJson("/api/v1/projects/{$this->project->id}/test-runs", $payload)->assertCreated();
        $this->assertSame(V::Verified, $feature->fresh()->verification_status);
    }

    public function test_checks_crud_and_access(): void
    {
        $feature = Feature::factory()->for($this->project)->create();

        $id = $this->postJson("/api/v1/features/{$feature->id}/checks", ['pattern' => 'Tests\\Feature\\InvoiceTest', 'description' => 'API tests'])
            ->assertCreated()->assertJsonPath('data.latest_result', null)->json('data.id');
        $this->postJson("/api/v1/features/{$feature->id}/checks", ['pattern' => 'Tests\\Feature\\InvoiceTest'])
            ->assertUnprocessable()->assertJsonValidationErrors('pattern');
        $this->deleteJson("/api/v1/checks/{$id}")->assertNoContent();

        $foreign = FeatureCheck::factory()->create();
        $this->getJson("/api/v1/features/{$foreign->feature_id}/checks")->assertForbidden();
        $this->postJson("/api/v1/features/{$foreign->feature_id}/checks", ['pattern' => 'x'])->assertForbidden();
        $this->deleteJson("/api/v1/checks/{$foreign->id}")->assertForbidden();
        $this->postJson('/api/v1/projects/'.$foreign->feature->project_id.'/test-runs', ['cases' => [self::case('x')]])->assertForbidden();
    }

    public function test_test_run_listing_and_detail(): void
    {
        $this->feature(['Tests\\Feature\\InvoiceTest']);
        $id = $this->submitRun([self::case('Tests\\Feature\\InvoiceTest::test_list')])->json('data.id');

        $this->getJson("/api/v1/projects/{$this->project->id}/test-runs")->assertOk()->assertJsonPath('data.0.id', $id);
        $this->getJson("/api/v1/test-runs/{$id}")
            ->assertOk()
            ->assertJsonPath('data.check_results.0.pattern', 'Tests\\Feature\\InvoiceTest')
            ->assertJsonPath('data.check_results.0.outcome', 'passed');
    }
}
