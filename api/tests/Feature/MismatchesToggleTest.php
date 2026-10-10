<?php

namespace Tests\Feature;

use App\Livewire\ProjectForm;
use App\Livewire\ProjectOverview;
use App\Models\Project;
use App\Models\SpecMismatch;
use App\Support\NextSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class MismatchesToggleTest extends TestCase
{
    use RefreshDatabase;

    public function test_projects_track_prd_vs_design_by_default(): void
    {
        $project = Project::factory()->create();

        $this->assertTrue($project->fresh()->mismatches_enabled);
        $this->actingAs($project->owner)->get(route('mismatches.index', $project))->assertOk();
    }

    public function test_settings_turn_it_off_and_on(): void
    {
        $project = Project::factory()->create();
        $this->actingAs($project->owner);

        Livewire::test(ProjectForm::class, ['project' => $project])
            ->assertSet('mismatches_enabled', true)
            ->set('mismatches_enabled', false)
            ->call('save')
            ->assertHasNoErrors();
        $this->assertFalse($project->fresh()->mismatches_enabled);

        Livewire::test(ProjectForm::class, ['project' => $project->fresh()])
            ->assertSet('mismatches_enabled', false)
            ->set('mismatches_enabled', true)
            ->call('save');
        $this->assertTrue($project->fresh()->mismatches_enabled);
    }

    public function test_turned_off_it_is_hidden_and_kept(): void
    {
        $project = Project::factory()->create(['mismatches_enabled' => false]);
        SpecMismatch::factory()->for($project)->create(['title' => 'Promo code field']);
        SpecMismatch::factory()->for($project)->create(['status' => 'decided', 'decision' => 'follow_prd']);
        $this->actingAs($project->owner);

        $this->get(route('mismatches.index', $project))->assertNotFound();
        Livewire::test(ProjectOverview::class, ['project' => $project])
            ->assertDontSee('PRD vs design')
            ->assertDontSee('Promo code field');
        $this->assertFalse(NextSteps::for($project)->contains(fn ($s) => $s['run'] === 'apply_mismatches'));
        $this->assertStringNotContainsString('unseen mismatch', view('agent.claude-md', ['project' => $project, 'url' => 'http://x', 'name' => 'x'])->render());

        $project->update(['mismatches_enabled' => true]);
        Livewire::test(ProjectOverview::class, ['project' => $project->fresh()])->assertSee('Promo code field');
        $this->assertTrue(NextSteps::for($project->fresh())->contains(fn ($s) => $s['run'] === 'apply_mismatches'));
    }

    public function test_api_refuses_new_mismatches_when_off(): void
    {
        $project = Project::factory()->create(['mismatches_enabled' => false]);
        Sanctum::actingAs($project->owner, ['*']);

        $this->postJson("/api/v1/projects/{$project->id}/mismatches", ['title' => 'x', 'prd_says' => 'a', 'design_says' => 'b'])
            ->assertStatus(409)->assertJsonPath('message', fn ($m) => str_contains($m, 'turned off'));
        $this->getJson("/api/v1/projects/{$project->id}/dashboard")->assertOk()
            ->assertJsonPath('data.summary.mismatches', null)
            ->assertJsonPath('data.mismatches', []);
        $this->getJson("/api/v1/projects/{$project->id}")->assertJsonPath('data.mismatches_enabled', false);

        $this->patchJson("/api/v1/projects/{$project->id}", ['mismatches_enabled' => true])->assertOk()
            ->assertJsonPath('data.mismatches_enabled', true);
    }
}
