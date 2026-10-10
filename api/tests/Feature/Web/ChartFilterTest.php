<?php

namespace Tests\Feature\Web;

use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Livewire\ProjectOverview;
use App\Models\Feature;
use App\Models\FeatureCategory;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class ChartFilterTest extends TestCase
{
    use RefreshDatabase;

    public function test_clicking_a_chart_filters_the_feature_list(): void
    {
        $project = Project::factory()->create();
        $admin = FeatureCategory::factory()->for($project)->create(['name' => 'Admin panel']);
        $make = fn (string $name, FeatureStatus $s, VerificationStatus $v, ?int $cat) => Feature::factory()->for($project)
            ->withStatus($s, $v)->create(['name' => $name, 'category_id' => $cat]);
        $make('Approve users', FeatureStatus::Implemented, VerificationStatus::Verified, $admin->id);
        $make('Ban users', FeatureStatus::Implemented, VerificationStatus::NeedsVerification, $admin->id);
        $make('Export users', FeatureStatus::Planned, VerificationStatus::NotChecked, $admin->id);
        $make('Search', FeatureStatus::Implemented, VerificationStatus::Failed, null);
        $this->actingAs($project->owner);

        $names = fn (array $expected) => fn ($features) => $features->pluck('name')->sort()->values()->all() === $expected;
        $c = (string) $admin->id;

        Livewire::test(ProjectOverview::class, ['project' => $project])
            ->set('search', 'zzz')
            ->call('filterFeatures', $c)
            ->assertSet('search', '') // a chart click starts from a clean list
            ->assertViewHas('features', $names(['Approve users', 'Ban users', 'Export users']))
            ->assertViewHas('showing', 'Admin panel')
            ->call('filterFeatures', $c, 'implemented', 'unverified')
            ->assertViewHas('features', $names(['Ban users']))
            ->assertViewHas('showing', 'Admin panel · Implemented · Not verified')
            ->call('filterFeatures', $c, 'unbuilt')
            ->assertViewHas('features', $names(['Export users']))
            ->call('filterFeatures', $c, '', 'verified')
            ->assertViewHas('features', $names(['Approve users']))
            ->call('filterFeatures', 'none')
            ->assertViewHas('features', $names(['Search']))
            ->call('filterFeatures', '', '', 'failed')
            ->assertViewHas('features', $names(['Search']))
            ->call('clearFilters')
            ->assertViewHas('showing', null)
            ->assertViewHas('features', $names(['Approve users', 'Ban users', 'Export users', 'Search']));
    }

    public function test_unknown_values_and_other_projects_categories_are_ignored(): void
    {
        $project = Project::factory()->create();
        $foreign = FeatureCategory::factory()->create();
        $this->actingAs($project->owner);

        Livewire::test(ProjectOverview::class, ['project' => $project])
            ->call('filterFeatures', (string) $foreign->id, 'shipped', 'approved')
            ->assertSet('category', '')
            ->assertSet('status', '')
            ->assertSet('verification', '');
    }

    public function test_overview_renders_clickable_charts(): void
    {
        $project = Project::factory()->create();
        $cat = FeatureCategory::factory()->for($project)->create(['name' => 'Admin panel']);
        Feature::factory()->for($project)->withStatus(FeatureStatus::Planned)->create(['category_id' => $cat->id]);
        $this->actingAs($project->owner);

        $this->get("/projects/{$project->id}")->assertOk()
            ->assertSee("wire:click=\"filterFeatures('{$cat->id}')\"", false)
            ->assertSee("wire:click=\"filterFeatures(&#039;{$cat->id}&#039;, &#039;unbuilt&#039;)\"", false)
            ->assertSee('wire:click="filterFeatures(&#039;&#039;, &#039;planned&#039;)"', false);
    }
}
