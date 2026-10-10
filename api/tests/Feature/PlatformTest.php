<?php

namespace Tests\Feature;

use App\Enums\FeatureStatus;
use App\Enums\Platform;
use App\Enums\VerificationStatus as V;
use App\Livewire\ProjectForm;
use App\Livewire\ProjectOverview;
use App\Models\Feature;
use App\Models\Project;
use App\Services\ProjectDashboard;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    private function feature(Project $project, string $name, ?array $platforms, FeatureStatus $status = FeatureStatus::Planned, V $v = V::NotChecked): Feature
    {
        return Feature::factory()->for($project)->withStatus($status, $v)->create(['name' => $name, 'platforms' => $platforms]);
    }

    /** iOS + Android app: login is shared, Face ID is iOS only, widgets are Android only. */
    private function mobileProject(): Project
    {
        $project = Project::factory()->create(['platforms' => ['android', 'ios']]);
        $this->feature($project, 'Login', null, FeatureStatus::Implemented, V::Verified);
        $this->feature($project, 'Face ID', ['ios'], FeatureStatus::Implemented, V::Failed);
        $this->feature($project, 'Widgets', ['android'], FeatureStatus::Planned);

        return $project;
    }

    public function test_platforms_are_stored_in_order_without_duplicates_and_empty_means_all(): void
    {
        $project = Project::factory()->create(['platforms' => ['web', 'ios', 'web', 'nope']]);
        $this->assertSame(['ios', 'web'], $project->fresh()->platforms);
        $this->assertSame([Platform::Ios, Platform::Web], $project->fresh()->platformList());

        $feature = $this->feature($project, 'Shared', []);
        $this->assertNull($feature->fresh()->platforms);
        $this->assertTrue($feature->fresh()->isOnAllPlatforms());
    }

    public function test_on_platform_includes_tagged_and_untagged_features(): void
    {
        $project = $this->mobileProject();

        $names = fn (Platform $p) => $project->features()->onPlatform($p)->orderBy('name')->pluck('name')->all();
        $this->assertSame(['Face ID', 'Login'], $names(Platform::Ios));
        $this->assertSame(['Login', 'Widgets'], $names(Platform::Android));
    }

    public function test_dashboard_numbers_per_platform(): void
    {
        $project = $this->mobileProject();
        $dashboard = app(ProjectDashboard::class);

        $all = $dashboard->summary($project);
        $this->assertSame(3, $all['total']);
        $this->assertNull($all['platform']);
        $this->assertSame([
            ['platform' => 'ios', 'label' => 'iOS', 'total' => 2, 'implemented' => 2, 'verified' => 1, 'needs_attention' => 1, 'progress' => ['implemented_percent' => 100, 'verified_percent' => 50]],
            ['platform' => 'android', 'label' => 'Android', 'total' => 2, 'implemented' => 1, 'verified' => 1, 'needs_attention' => 0, 'progress' => ['implemented_percent' => 50, 'verified_percent' => 50]],
        ], $all['by_platform']);

        $ios = $dashboard->forPlatform(Platform::Ios)->summary($project);
        $this->assertSame('ios', $ios['platform']);
        $this->assertSame(2, $ios['total']);
        $this->assertSame(1, $ios['by_verification_status']['failed']);
        $this->assertSame(['Face ID'], $dashboard->forPlatform(Platform::Ios)->attention($project)->pluck('name')->all());
        $this->assertSame([], $dashboard->forPlatform(Platform::Android)->attention($project)->pluck('name')->all());
        $this->assertSame(3, $dashboard->summary($project)['total'], 'forPlatform() returns a copy');
    }

    public function test_agents_tag_features_with_the_projects_platforms(): void
    {
        $project = Project::factory()->create(['platforms' => ['ios', 'android']]);
        Sanctum::actingAs($project->owner, ['*']);

        $id = $this->postJson("/api/v1/projects/{$project->id}/features", ['name' => 'Face ID', 'platforms' => ['ios']])
            ->assertCreated()->assertJsonPath('data.platforms', ['ios'])->json('data.id');

        $this->postJson("/api/v1/projects/{$project->id}/features", ['name' => 'Dark mode', 'platforms' => ['web']])
            ->assertUnprocessable()->assertJsonValidationErrors('platforms.0');

        $this->patchJson("/api/v1/features/{$id}", ['platforms' => []])->assertOk()->assertJsonPath('data.platforms', []);
        $this->patchJson("/api/v1/features/{$id}", ['name' => 'Face ID login'])->assertOk()->assertJsonPath('data.platforms', []);

        $this->feature($project, 'Widgets', ['android']);
        $this->getJson("/api/v1/projects/{$project->id}/features?platform=ios")->assertOk()
            ->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Face ID login');
    }

    public function test_project_platforms_through_the_api_and_dashboard_filter(): void
    {
        $project = $this->mobileProject();
        Sanctum::actingAs($project->owner, ['*']);

        $this->patchJson("/api/v1/projects/{$project->id}", ['platforms' => ['tv']])->assertJsonValidationErrors('platforms.0');
        $this->getJson("/api/v1/projects/{$project->id}")->assertJsonPath('data.platforms', ['ios', 'android']);

        $this->getJson("/api/v1/projects/{$project->id}/dashboard?platform=android")->assertOk()
            ->assertJsonPath('data.summary.platform', 'android')
            ->assertJsonPath('data.summary.total', 2)
            ->assertJsonCount(2, 'data.summary.by_platform');
        $this->getJson("/api/v1/projects/{$project->id}/dashboard?platform=web")->assertJsonValidationErrors('platform');
    }

    public function test_project_settings_save_platforms(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->owner);

        Livewire::test(ProjectForm::class, ['project' => $project])
            ->set('platforms', ['web', 'ios'])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame(['ios', 'web'], $project->fresh()->platforms);
    }

    public function test_dashboard_switches_between_platforms(): void
    {
        $project = $this->mobileProject();
        $this->actingAs($project->owner);
        $names = fn (array $expected) => fn ($features) => $features->pluck('name')->sort()->values()->all() === $expected;

        Livewire::test(ProjectOverview::class, ['project' => $project])
            ->assertSee('All platforms')
            ->assertViewHas('features', $names(['Face ID', 'Login', 'Widgets']))
            ->call('showPlatform', 'ios')
            ->assertViewHas('activePlatform', Platform::Ios)
            ->assertViewHas('features', $names(['Face ID', 'Login']))
            ->assertViewHas('summary', fn ($s) => $s['total'] === 2)
            ->call('clearFilters')
            ->assertSet('platform', 'ios') // the platform is the view, not a filter
            ->call('showPlatform', 'web') // not one of the project's platforms
            ->assertViewHas('activePlatform', null)
            ->assertViewHas('features', $names(['Face ID', 'Login', 'Widgets']));
    }

    public function test_single_platform_projects_show_no_switcher(): void
    {
        $project = Project::factory()->create(['platforms' => ['web']]);
        $this->actingAs($project->owner);

        Livewire::test(ProjectOverview::class, ['project' => $project])
            ->assertSee('Web')
            ->assertDontSee('All platforms');
    }
}
