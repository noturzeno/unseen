<?php

namespace Tests\Feature\Api\V1;

use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\FeatureCategory;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class FeatureApiTest extends TestCase
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

    // --- CRUD -----------------------------------------------------------

    public function test_create_feature_with_defaults_and_criteria(): void
    {
        $category = FeatureCategory::factory()->for($this->project)->create();

        $id = $this->postJson("/api/v1/projects/{$this->project->id}/features", [
            'name' => 'Password reset',
            'description' => 'Users can reset a forgotten password by email.',
            'category_id' => $category->id,
            'acceptance_criteria' => ['Reset link expires after 60 minutes', 'Old password stops working'],
        ])
            ->assertCreated()
            ->assertJsonPath('data.status', 'planned')
            ->assertJsonPath('data.verification_status', 'not_checked')
            ->assertJsonPath('data.category.id', $category->id)
            ->assertJsonPath('data.acceptance_criteria.0.description', 'Reset link expires after 60 minutes')
            ->assertJsonPath('data.acceptance_criteria.1.position', 1)
            ->assertJsonPath('data.acceptance_criteria.0.source', 'agent_reported')
            ->json('data.id');

        $this->getJson("/api/v1/features/{$id}/history")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonFragment(['field' => 'status', 'from' => null, 'to' => 'planned', 'source' => 'agent_reported']);
    }

    public function test_create_rejects_invalid_statuses_and_foreign_category(): void
    {
        $foreignCategory = FeatureCategory::factory()->create();

        $this->postJson("/api/v1/projects/{$this->project->id}/features", [
            'name' => 'X',
            'status' => 'done',
            'verification_status' => 'probably',
            'category_id' => $foreignCategory->id,
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['status', 'verification_status', 'category_id']);
    }

    public function test_status_and_verification_cannot_be_mass_assigned_past_the_service(): void
    {
        $feature = new Feature(['name' => 'x', 'status' => 'implemented', 'verification_status' => 'verified']);

        $this->assertSame(FeatureStatus::Planned, $feature->status);
        $this->assertSame(VerificationStatus::NotChecked, $feature->verification_status);
    }

    public function test_update_replaces_acceptance_criteria_only_when_given(): void
    {
        $feature = $this->feature();
        $feature->acceptanceCriteria()->create(['description' => 'Original', 'position' => 0, 'source' => 'user_entered']);

        $this->patchJson("/api/v1/features/{$feature->id}", ['name' => 'Renamed'])
            ->assertOk()
            ->assertJsonCount(1, 'data.acceptance_criteria');

        $this->patchJson("/api/v1/features/{$feature->id}", ['acceptance_criteria' => ['A', 'B']])
            ->assertOk()
            ->assertJsonCount(2, 'data.acceptance_criteria')
            ->assertJsonPath('data.acceptance_criteria.0.description', 'A');

        $this->patchJson("/api/v1/features/{$feature->id}", ['acceptance_criteria' => []])
            ->assertOk()
            ->assertJsonCount(0, 'data.acceptance_criteria');
    }

    public function test_index_filters(): void
    {
        $category = FeatureCategory::factory()->for($this->project)->create();
        $a = Feature::factory()->for($this->project)->withStatus(FeatureStatus::Blocked)->create(['category_id' => $category->id]);
        $b = Feature::factory()->for($this->project)->withStatus(FeatureStatus::Implemented, VerificationStatus::NeedsVerification)->create();
        Feature::factory()->withStatus(FeatureStatus::Blocked)->create(); // other project

        $ids = fn (string $query) => collect($this->getJson("/api/v1/projects/{$this->project->id}/features{$query}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEqualsCanonicalizing([$a->id, $b->id], $ids(''));
        $this->assertEquals([$a->id], $ids('?status=blocked'));
        $this->assertEquals([$b->id], $ids('?verification_status=needs_verification'));
        $this->assertEquals([$a->id], $ids("?category_id={$category->id}"));
        $this->assertEquals([$b->id], $ids('?category_id=none'));
        $this->getJson("/api/v1/projects/{$this->project->id}/features?status=nope")->assertUnprocessable();
    }

    // --- Authorization --------------------------------------------------

    public function test_outsiders_cannot_read_or_write_features(): void
    {
        $foreign = Feature::factory()->create();

        $this->getJson("/api/v1/projects/{$foreign->project_id}/features")->assertForbidden();
        $this->postJson("/api/v1/projects/{$foreign->project_id}/features", ['name' => 'X'])->assertForbidden();
        $this->getJson("/api/v1/features/{$foreign->id}")->assertForbidden();
        $this->patchJson("/api/v1/features/{$foreign->id}", ['status' => 'blocked'])->assertForbidden();
        $this->getJson("/api/v1/features/{$foreign->id}/history")->assertForbidden();

        $this->assertSame(FeatureStatus::Planned, $foreign->fresh()->status);
    }

    public function test_members_can_manage_features(): void
    {
        $other = Project::factory()->create();
        $other->members()->attach($this->user);

        $this->postJson("/api/v1/projects/{$other->id}/features", ['name' => 'Shared'])->assertCreated();
    }

    public function test_guests_are_rejected(): void
    {
        $feature = $this->feature();
        $this->app['auth']->forgetGuards();

        $this->withHeader('Authorization', '')->getJson("/api/v1/features/{$feature->id}")->assertUnauthorized();
    }

    // --- Status transitions ---------------------------------------------

    public function test_status_change_is_recorded_in_history(): void
    {
        $feature = $this->feature();

        $this->patchJson("/api/v1/features/{$feature->id}", ['status' => 'in_progress', 'status_note' => 'Started work'])
            ->assertOk()
            ->assertJsonPath('data.status', 'in_progress');

        $this->assertDatabaseHas('feature_status_history', [
            'feature_id' => $feature->id,
            'field' => 'status',
            'from_value' => 'planned',
            'to_value' => 'in_progress',
            'source' => 'agent_reported',
            'user_id' => $this->user->id,
            'note' => 'Started work',
        ]);
    }

    public function test_unchanged_status_writes_no_history(): void
    {
        $feature = $this->feature(FeatureStatus::InProgress);

        $this->patchJson("/api/v1/features/{$feature->id}", ['status' => 'in_progress'])->assertOk();

        $this->assertDatabaseCount('feature_status_history', 0);
    }

    public function test_implemented_moves_not_checked_to_needs_verification(): void
    {
        $feature = $this->feature(FeatureStatus::InProgress);

        $this->patchJson("/api/v1/features/{$feature->id}", ['status' => 'implemented'])
            ->assertOk()
            ->assertJsonPath('data.status', 'implemented')
            ->assertJsonPath('data.verification_status', 'needs_verification');

        $this->assertDatabaseHas('feature_status_history', [
            'feature_id' => $feature->id,
            'field' => 'verification_status',
            'from_value' => 'not_checked',
            'to_value' => 'needs_verification',
        ]);
    }

    public function test_verified_requires_implemented(): void
    {
        $feature = $this->feature(FeatureStatus::InProgress);

        $this->patchJson("/api/v1/features/{$feature->id}", ['verification_status' => 'verified'])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('verification_status');

        $this->postJson("/api/v1/projects/{$this->project->id}/features", ['name' => 'X', 'verification_status' => 'verified'])
            ->assertUnprocessable();

        $this->assertSame(VerificationStatus::NotChecked, $feature->fresh()->verification_status);
        $this->assertDatabaseCount('feature_status_history', 0);
    }

    public function test_api_cannot_mark_features_verified_or_failed(): void
    {
        $feature = $this->feature(FeatureStatus::Implemented, VerificationStatus::NeedsVerification);

        foreach (['verified', 'failed'] as $value) {
            $this->patchJson("/api/v1/features/{$feature->id}", ['verification_status' => $value])
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['verification_status' => 'Only trusted test runs']);
        }

        $this->assertSame(VerificationStatus::NeedsVerification, $feature->fresh()->verification_status);
    }

    public function test_api_can_ask_for_verification_again(): void
    {
        $feature = $this->feature(FeatureStatus::Implemented, VerificationStatus::NotChecked);

        $this->patchJson("/api/v1/features/{$feature->id}", ['verification_status' => 'needs_verification'])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'needs_verification');
    }

    public function test_changing_status_of_verified_feature_resets_verification(): void
    {
        $feature = $this->feature(FeatureStatus::Implemented, VerificationStatus::Verified);

        $this->patchJson("/api/v1/features/{$feature->id}", ['status' => 'in_progress'])
            ->assertOk()
            ->assertJsonPath('data.verification_status', 'needs_verification');

        $this->assertDatabaseHas('feature_status_history', [
            'feature_id' => $feature->id,
            'field' => 'verification_status',
            'from_value' => 'verified',
            'to_value' => 'needs_verification',
            'note' => 'Verification reset because the implementation status changed.',
        ]);
    }

    public function test_history_is_newest_first(): void
    {
        $feature = $this->feature();

        $this->patchJson("/api/v1/features/{$feature->id}", ['status' => 'in_progress'])->assertOk();
        $this->patchJson("/api/v1/features/{$feature->id}", ['status' => 'blocked'])->assertOk();

        $this->getJson("/api/v1/features/{$feature->id}/history")
            ->assertOk()
            ->assertJsonPath('data.0.to', 'blocked')
            ->assertJsonPath('data.0.user.id', $this->user->id)
            ->assertJsonPath('data.1.to', 'in_progress');
    }
}
