<?php

namespace Tests\Feature\Api\V1;

use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\DevelopmentReport;
use App\Models\Feature;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class DevelopmentReportApiTest extends TestCase
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

    private function feature(FeatureStatus $status = FeatureStatus::Planned, VerificationStatus $verification = VerificationStatus::NotChecked): Feature
    {
        return Feature::factory()->for($this->project)->withStatus($status, $verification)->create();
    }

    private function submit(array $payload)
    {
        return $this->postJson("/api/v1/projects/{$this->project->id}/reports", $payload);
    }

    public function test_full_report_is_stored(): void
    {
        $feature = $this->feature(FeatureStatus::InProgress);

        $id = $this->submit([
            'summary' => 'Added password reset flow.',
            'agent' => 'claude-code',
            'confidence' => 'medium',
            'limitations' => 'Email delivery not tested against a real mail server.',
            'git_branch' => 'feature/password-reset',
            'git_commit' => 'a1b2c3d4e5f6',
            'features' => [['id' => $feature->id, 'status' => 'implemented', 'note' => 'Controller and views added']],
            'changed_files' => [['path' => 'app/Http/Controllers/PasswordResetController.php', 'change' => 'added']],
            'tests' => [
                ['command' => 'php artisan test --filter=PasswordReset', 'outcome' => 'passed'],
                ['command' => 'npm run e2e', 'outcome' => 'not_run', 'summary' => 'No browser available'],
            ],
            'unresolved' => ['Rate limiting on reset requests'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.source', 'agent_reported')
            ->assertJsonPath('data.submitted_by.id', $this->user->id)
            ->assertJsonPath('data.git.commit', 'a1b2c3d4e5f6')
            ->assertJsonPath('data.features.0.id', $feature->id)
            ->assertJsonPath('data.features.0.claimed_status', 'implemented')
            ->assertJsonPath('data.test_counts', ['passed' => 1, 'not_run' => 1])
            ->assertJsonPath('data.unresolved.0', 'Rate limiting on reset requests')
            ->json('data.id');

        $this->getJson("/api/v1/reports/{$id}")->assertOk()->assertJsonPath('data.summary', 'Added password reset flow.');
        $this->assertDatabaseHas('feature_evidence', [
            'feature_id' => $feature->id, 'development_report_id' => $id, 'source' => 'agent_reported', 'claimed_status' => 'implemented',
        ]);
        $this->assertSame(0, DevelopmentReport::find($id)->tests_failed_count);
    }

    public function test_minimal_report_only_needs_a_summary(): void
    {
        $this->submit(['summary' => 'Refactored config loading.'])
            ->assertCreated()
            ->assertJsonPath('data.features', [])
            ->assertJsonPath('data.changed_files', []);
    }

    public function test_claimed_implemented_is_applied_but_never_verified(): void
    {
        $feature = $this->feature(FeatureStatus::InProgress);

        $this->submit(['summary' => 'Done', 'features' => [['id' => $feature->id, 'status' => 'implemented']]])->assertCreated();

        $feature->refresh();
        $this->assertSame(FeatureStatus::Implemented, $feature->status);
        $this->assertSame(VerificationStatus::NeedsVerification, $feature->verification_status);
        $this->assertDatabaseHas('feature_status_history', [
            'feature_id' => $feature->id, 'field' => 'status', 'to_value' => 'implemented', 'source' => 'agent_reported',
        ]);
    }

    public function test_report_cannot_claim_verification(): void
    {
        $feature = $this->feature(FeatureStatus::Implemented, VerificationStatus::NeedsVerification);

        $this->submit([
            'summary' => 'All verified',
            'verification_status' => 'verified',
            'features' => [['id' => $feature->id, 'status' => 'implemented', 'verification_status' => 'verified']],
            'tests' => [['command' => 'php artisan test', 'outcome' => 'passed']],
        ])->assertCreated();

        $this->assertSame(VerificationStatus::NeedsVerification, $feature->fresh()->verification_status);
    }

    public function test_touching_a_verified_feature_resets_verification(): void
    {
        $feature = $this->feature(FeatureStatus::Implemented, VerificationStatus::Verified);

        $this->submit(['summary' => 'Tweaked login', 'features' => [['id' => $feature->id]]])->assertCreated();

        $feature->refresh();
        $this->assertSame(FeatureStatus::Implemented, $feature->status);
        $this->assertSame(VerificationStatus::NeedsVerification, $feature->verification_status);
        $this->assertDatabaseHas('feature_status_history', [
            'feature_id' => $feature->id, 'field' => 'verification_status', 'from_value' => 'verified', 'to_value' => 'needs_verification', 'source' => 'agent_reported',
        ]);
    }

    public function test_touching_a_failed_feature_moves_it_back_to_needs_verification(): void
    {
        $feature = $this->feature(FeatureStatus::Implemented, VerificationStatus::Failed);

        $this->submit(['summary' => 'Fixed bug', 'features' => [['id' => $feature->id, 'status' => 'implemented']]])->assertCreated();

        $this->assertSame(VerificationStatus::NeedsVerification, $feature->fresh()->verification_status);
    }

    public function test_mentioning_a_feature_without_status_changes_nothing_but_adds_evidence(): void
    {
        $feature = $this->feature(FeatureStatus::InProgress);

        $this->submit(['summary' => 'Partial work', 'features' => [['id' => $feature->id, 'note' => 'Started UI']]])->assertCreated();

        $this->assertSame(FeatureStatus::InProgress, $feature->fresh()->status);
        $this->assertDatabaseCount('feature_status_history', 0);
        $this->getJson("/api/v1/features/{$feature->id}/evidence")
            ->assertOk()
            ->assertJsonPath('data.0.note', 'Started UI')
            ->assertJsonPath('data.0.report.summary', 'Partial work');
    }

    public function test_failed_tests_are_counted(): void
    {
        $id = $this->submit(['summary' => 'x', 'tests' => [
            ['command' => 'a', 'outcome' => 'failed'],
            ['command' => 'b', 'outcome' => 'error'],
            ['command' => 'c', 'outcome' => 'passed'],
        ]])->assertCreated()->json('data.id');

        $this->assertSame(2, DevelopmentReport::find($id)->tests_failed_count);
    }

    public function test_payload_validation(): void
    {
        $foreign = Feature::factory()->create();
        $mine = $this->feature();

        $this->submit([
            'summary' => '',
            'confidence' => 'certain',
            'git_commit' => 'not-a-sha',
            'reported_at' => now()->addDay()->toIso8601String(),
            'features' => [['id' => $foreign->id], ['id' => $mine->id, 'status' => 'done'], ['id' => $mine->id]],
            'changed_files' => [['change' => 'modified'], ['path' => 'a', 'change' => 'exploded']],
            'tests' => [['command' => 'x'], ['outcome' => 'passed']],
            'unresolved' => [''],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'summary', 'confidence', 'git_commit', 'reported_at',
            'features.0.id', 'features.1.status', 'features.2.id',
            'changed_files.0.path', 'changed_files.1.change',
            'tests.0.outcome', 'tests.1.command', 'unresolved.0',
        ]);

        $this->assertDatabaseCount('development_reports', 0);
    }

    public function test_unknown_keys_in_list_items_are_not_stored(): void
    {
        $id = $this->submit(['summary' => 'x', 'changed_files' => [['path' => 'a.php', 'secret' => 'nope']]])
            ->assertCreated()->json('data.id');

        $this->assertSame([['path' => 'a.php']], DevelopmentReport::find($id)->changed_files);
    }

    public function test_index_lists_newest_first_and_filters_by_feature(): void
    {
        $feature = $this->feature();
        $old = DevelopmentReport::factory()->for($this->project)->create(['reported_at' => now()->subDay()]);
        $new = $this->submit(['summary' => 'New', 'features' => [['id' => $feature->id]]])->json('data.id');
        DevelopmentReport::factory()->create(); // other project

        $this->getJson("/api/v1/projects/{$this->project->id}/reports")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.id', $new)
            ->assertJsonPath('data.1.id', $old->id);

        $this->getJson("/api/v1/projects/{$this->project->id}/reports?feature_id={$feature->id}")
            ->assertOk()
            ->assertJsonCount(1, 'data');
    }

    public function test_authorization(): void
    {
        $other = Project::factory()->create();
        $report = DevelopmentReport::factory()->for($other)->create();
        $feature = Feature::factory()->for($other)->create();

        $this->getJson("/api/v1/projects/{$other->id}/reports")->assertForbidden();
        $this->postJson("/api/v1/projects/{$other->id}/reports", ['summary' => 'x'])->assertForbidden();
        $this->getJson("/api/v1/reports/{$report->id}")->assertForbidden();
        $this->getJson("/api/v1/features/{$feature->id}/evidence")->assertForbidden();

        $other->members()->attach($this->user);
        $this->postJson("/api/v1/projects/{$other->id}/reports", ['summary' => 'Member report'])->assertCreated();
    }

    public function test_guests_cannot_submit(): void
    {
        $this->app['auth']->forgetGuards();

        $this->postJson("/api/v1/projects/{$this->project->id}/reports", ['summary' => 'x'])->assertUnauthorized();
    }

    public function test_reports_are_append_only(): void
    {
        $report = DevelopmentReport::factory()->for($this->project)->create();

        $this->patchJson("/api/v1/reports/{$report->id}", ['summary' => 'edited'])->assertMethodNotAllowed();
        $this->deleteJson("/api/v1/reports/{$report->id}")->assertMethodNotAllowed();
    }
}
