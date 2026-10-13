<?php

namespace Tests\Feature;

use App\Livewire\ProjectForm;
use App\Livewire\ProjectOverview;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class CiNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_settings_flag_and_unflag_running_out_of_actions_minutes(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->owner);

        Livewire::test(ProjectForm::class, ['project' => $project])
            ->assertSet('actions_minutes_out', false)
            ->set('actions_minutes_out', true)
            ->call('save')
            ->assertHasNoErrors();
        $flaggedAt = $project->fresh()->actions_minutes_out_at;
        $this->assertNotNull($flaggedAt);

        // Saving again while it stays flagged keeps the original time, so dismissals still hold.
        $this->travel(5)->minutes();
        Livewire::test(ProjectForm::class, ['project' => $project->fresh()])
            ->assertSet('actions_minutes_out', true)
            ->set('name', 'Renamed')
            ->call('save');
        $this->assertTrue($flaggedAt->eq($project->fresh()->actions_minutes_out_at));

        Livewire::test(ProjectForm::class, ['project' => $project->fresh()])
            ->set('actions_minutes_out', false)
            ->call('save');
        $this->assertNull($project->fresh()->actions_minutes_out_at);
    }

    public function test_dashboard_shows_the_notice_with_a_link_to_the_guide_only_when_flagged(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->owner);

        Livewire::test(ProjectOverview::class, ['project' => $project])->assertDontSee('CI needs a local runner');

        $project->update(['actions_minutes_out_at' => now()]);
        Livewire::test(ProjectOverview::class, ['project' => $project->fresh()])
            ->assertSee('CI needs a local runner')
            ->assertSee(route('guides.self-hosted-runner'))
            ->assertSee('Dismiss');
    }

    public function test_dismissing_hides_it_for_that_person_only_until_it_is_flagged_again(): void
    {
        $project = Project::factory()->create(['actions_minutes_out_at' => now()]);
        $member = User::factory()->create();
        $project->members()->attach($member);

        $this->actingAs($project->owner);
        Livewire::test(ProjectOverview::class, ['project' => $project])
            ->call('dismissCiNotice')
            ->assertDontSee('CI needs a local runner');
        Livewire::test(ProjectOverview::class, ['project' => $project->fresh()])->assertDontSee('CI needs a local runner');

        $this->actingAs($member);
        Livewire::test(ProjectOverview::class, ['project' => $project->fresh()])->assertSee('CI needs a local runner');

        // Unflagged, then flagged again later: everyone sees it again.
        $this->travel(1)->day();
        $project->update(['actions_minutes_out_at' => null]);
        $project->update(['actions_minutes_out_at' => now()]);
        $this->actingAs($project->owner);
        Livewire::test(ProjectOverview::class, ['project' => $project->fresh()])->assertSee('CI needs a local runner');
    }

    public function test_the_api_cannot_set_the_flag(): void
    {
        $project = Project::factory()->create();
        Sanctum::actingAs($project->owner, ['*']);

        $this->patchJson("/api/v1/projects/{$project->id}", ['actions_minutes_out_at' => now()->toIso8601String()])->assertOk();
        $this->assertNull($project->fresh()->actions_minutes_out_at);
    }
}
