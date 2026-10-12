<?php

namespace Tests\Feature\Api\V1;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus as V;
use App\Models\DevelopmentReport;
use App\Models\Feature;
use App\Models\FeatureCategory;
use App\Models\Project;
use App\Models\User;
use App\Services\FeatureStatusService;
use App\Services\ProjectDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectDashboardTest extends TestCase
{
    use RefreshDatabase;

    private function make(Project $project, FeatureStatus $status, V $verification = V::NotChecked, int $count = 1): void
    {
        Feature::factory()->count($count)->for($project)->withStatus($status, $verification)->create();
    }

    public function test_progress_formula(): void
    {
        $this->assertSame(['implemented_percent' => null, 'verified_percent' => null], ProjectDashboard::progress(0, 0, 0));
        $this->assertSame(['implemented_percent' => 66, 'verified_percent' => 33], ProjectDashboard::progress(2, 1, 3));
        $this->assertSame(['implemented_percent' => 99, 'verified_percent' => 0], ProjectDashboard::progress(199, 0, 200));
        $this->assertSame(['implemented_percent' => 100, 'verified_percent' => 100], ProjectDashboard::progress(4, 4, 4));
    }

    public function test_summary_counts(): void
    {
        $project = Project::factory()->create();
        $this->make($project, FeatureStatus::Implemented, V::Verified, 2);
        $this->make($project, FeatureStatus::Implemented, V::NeedsVerification);
        $this->make($project, FeatureStatus::Implemented, V::Failed);
        $this->make($project, FeatureStatus::Blocked);
        $this->make($project, FeatureStatus::Planned, V::NotChecked, 3);
        $this->make(Project::factory()->create(), FeatureStatus::Implemented, V::Verified, 5); // other project

        $summary = app(ProjectDashboard::class)->summary($project);

        $this->assertSame(8, $summary['total']);
        $this->assertSame(['planned' => 3, 'in_progress' => 0, 'implemented' => 4, 'blocked' => 1, 'not_implemented' => 0, 'ignored' => 0], $summary['by_status']);
        $this->assertSame(['not_checked' => 4, 'needs_verification' => 1, 'verified' => 2, 'failed' => 1], $summary['by_verification_status']);
        $this->assertSame(2, $summary['implemented_and_verified']);
        $this->assertSame(3, $summary['needs_attention']); // failed + blocked + needs_verification
        $this->assertSame(['implemented_percent' => 50, 'verified_percent' => 25], $summary['progress']);
    }

    public function test_attention_list_puts_failures_first(): void
    {
        $project = Project::factory()->create();
        $this->make($project, FeatureStatus::Implemented, V::NeedsVerification);
        $this->make($project, FeatureStatus::Blocked);
        $this->make($project, FeatureStatus::Implemented, V::Failed);
        $this->make($project, FeatureStatus::Implemented, V::Verified);

        $attention = app(ProjectDashboard::class)->attention($project);

        $this->assertCount(3, $attention);
        $this->assertSame(V::Failed, $attention[0]->verification_status);
        $this->assertSame(FeatureStatus::Blocked, $attention[1]->status);
        $this->assertSame(V::NeedsVerification, $attention[2]->verification_status);
    }

    public function test_recent_changes_are_scoped_to_the_project(): void
    {
        $project = Project::factory()->create();
        $mine = Feature::factory()->for($project)->create();
        $other = Feature::factory()->create();
        $service = app(FeatureStatusService::class);
        $service->apply($mine, FeatureStatus::InProgress, null, ClaimSource::UserEntered);
        $service->apply($other, FeatureStatus::InProgress, null, ClaimSource::UserEntered);

        $changes = app(ProjectDashboard::class)->recentChanges($project);

        $this->assertCount(1, $changes);
        $this->assertSame($mine->id, $changes[0]->feature_id);
    }

    public function test_dashboard_endpoint(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->for($user, 'owner')->create();
        $this->make($project, FeatureStatus::Implemented, V::Verified);
        $this->make($project, FeatureStatus::Blocked);

        $this->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonPath('data.summary.progress.implemented_percent', 50)
            ->assertJsonPath('data.summary.progress.verified_percent', 50)
            ->assertJsonCount(1, 'data.needs_attention')
            ->assertJsonCount(2, 'data.recently_updated')
            ->assertJsonStructure(['data' => ['recent_changes']]);
    }

    public function test_dashboard_includes_reports_and_unresolved_work(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->for($user, 'owner')->create();
        foreach (range(1, 6) as $i) {
            $this->travel(1)->minutes();
            $this->postJson("/api/v1/projects/{$project->id}/reports", ['summary' => "Report {$i}", 'unresolved' => ["Item from report {$i}"]])->assertCreated();
        }
        $first = $project->unresolvedItems()->firstWhere('text', 'Item from report 1');
        $this->postJson("/api/v1/unresolved/{$first->id}/resolve")->assertOk();

        $response = $this->getJson("/api/v1/projects/{$project->id}/dashboard")
            ->assertOk()
            ->assertJsonPath('data.summary.reports', 6)
            ->assertJsonPath('data.summary.open_unresolved', 5)
            ->assertJsonCount(5, 'data.recent_reports')
            ->assertJsonCount(5, 'data.unresolved')
            ->assertJsonPath('data.unresolved.0.text', 'Item from report 6');

        $this->assertNotContains('Item from report 1', collect($response->json('data.unresolved'))->pluck('text'));
    }

    public function test_reports_count_as_project_activity(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->for($user, 'owner')->create(['updated_at' => now()->subWeek()]);
        DevelopmentReport::factory()->for($project)->create();

        $lastActivity = $this->getJson('/api/v1/projects')->assertOk()->json('data.0.last_activity_at');

        $this->assertTrue(now()->subMinute()->lt($lastActivity));
    }

    public function test_dashboard_requires_access(): void
    {
        $project = Project::factory()->create();

        $this->getJson("/api/v1/projects/{$project->id}/dashboard")->assertUnauthorized();

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJson("/api/v1/projects/{$project->id}/dashboard")->assertForbidden();
    }

    public function test_project_list_includes_progress_summary(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->for($user, 'owner')->create();
        $this->make($project, FeatureStatus::Implemented, V::NeedsVerification);
        $this->make($project, FeatureStatus::Planned);

        $this->getJson('/api/v1/projects')
            ->assertOk()
            ->assertJsonPath('data.0.summary.total', 2)
            ->assertJsonPath('data.0.summary.implemented', 1)
            ->assertJsonPath('data.0.summary.needs_attention', 1)
            ->assertJsonPath('data.0.summary.progress.implemented_percent', 50)
            ->assertJsonPath('data.0.summary.progress.verified_percent', 0);
    }

    public function test_by_category_counts_verified_as_implemented_and_verified(): void
    {
        $project = Project::factory()->create();
        $billing = FeatureCategory::factory()->for($project)->create(['name' => 'Billing']);
        $auth = FeatureCategory::factory()->for($project)->create(['name' => 'Auth']);
        FeatureCategory::factory()->for($project)->create(['name' => 'Empty']);
        Feature::factory()->for($project)->withStatus(FeatureStatus::Implemented, V::Verified)->create(['category_id' => $billing->id]);
        Feature::factory()->for($project)->withStatus(FeatureStatus::Implemented, V::NeedsVerification)->create(['category_id' => $billing->id]);
        Feature::factory()->for($project)->withStatus(FeatureStatus::Planned)->create(['category_id' => $auth->id]);
        $this->make($project, FeatureStatus::Implemented);

        $this->assertSame([
            ['id' => (string) $auth->id, 'name' => 'Auth', 'total' => 1, 'implemented' => 0, 'verified' => 0],
            ['id' => (string) $billing->id, 'name' => 'Billing', 'total' => 2, 'implemented' => 2, 'verified' => 1],
            ['id' => 'none', 'name' => 'Uncategorised', 'total' => 1, 'implemented' => 1, 'verified' => 0],
        ], app(ProjectDashboard::class)->byCategory($project)->all());
    }

    public function test_weekly_activity_buckets_status_changes_by_week(): void
    {
        $project = Project::factory()->create();
        $feature = Feature::factory()->for($project)->create();
        $statuses = app(FeatureStatusService::class);

        $this->travelTo(now()->startOfWeek()->subWeeks(2)->addDay());
        $statuses->apply($feature, FeatureStatus::InProgress, null, ClaimSource::UserEntered);
        $this->travelBack();
        $statuses->apply($feature->fresh(), FeatureStatus::Implemented, null, ClaimSource::UserEntered); // status + auto verification
        $statuses->apply(Feature::factory()->create(), FeatureStatus::Blocked, null, ClaimSource::UserEntered); // other project

        $weeks = app(ProjectDashboard::class)->weeklyActivity($project, 4);

        $this->assertCount(4, $weeks);
        $this->assertSame([0, 1, 0, 2], array_column($weeks, 'count'));
        $this->assertTrue($weeks[3]['week']->equalTo(now()->startOfWeek()));
    }
}
