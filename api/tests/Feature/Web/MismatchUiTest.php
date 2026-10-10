<?php

namespace Tests\Feature\Web;

use App\Models\Feature;
use App\Models\Project;
use App\Models\SpecMismatch;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class MismatchUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user, 'owner')->create();
        $this->actingAs($this->user);
    }

    public function test_page_shows_both_sides_and_who_decides(): void
    {
        $feature = Feature::factory()->for($this->project)->create(['name' => 'PRM-01 Promo codes']);
        SpecMismatch::factory()->for($this->project)->for($feature)->create([
            'title' => 'Promo list vs text box', 'prd_says' => 'Show available codes', 'design_says' => 'Only a text box', 'decide_by' => 'client',
        ]);
        SpecMismatch::factory()->for($this->project)->create(['title' => 'Plan step order', 'decide_by' => 'team']);

        $this->get("/projects/{$this->project->id}/mismatches")->assertOk()
            ->assertSeeInOrder(['Your decision needed', 'Plan step order', 'Client decision needed', 'Promo list vs text box'])
            ->assertSee('PRD says')->assertSee('Show available codes')->assertSee('Design says')->assertSee('Only a text box')
            ->assertSee('Built to the design')->assertSee('Follow the PRD')->assertSee('Keep the design');

        $this->get("/projects/{$this->project->id}")->assertOk()
            ->assertSee('PRD vs design')->assertSee('1 need your decision')->assertSee('1 need the client');
        $this->get("/features/{$feature->id}")->assertOk()->assertSee('Promo list vs text box');
    }

    public function test_deciding_and_changing_a_decision(): void
    {
        $m = SpecMismatch::factory()->for($this->project)->create();

        Livewire::test('mismatches-index', ['project' => $this->project])
            ->call('decide', $m->id, 'other')->assertHasErrors('decision_note')
            ->set("notes.{$m->id}", 'Use the design but keep the PRD wording')
            ->call('decide', $m->id, 'other')->assertHasNoErrors()
            ->assertSee('Decision: Something else')->assertSee('Apply 1 decision');

        $m->refresh();
        $this->assertSame('decided', $m->status->value);
        $this->assertSame($this->user->id, $m->decided_by);

        Livewire::test('mismatches-index', ['project' => $this->project])->call('reopen', $m->id);
        $this->assertSame('open', $m->fresh()->status->value);
    }

    public function test_outsiders_cannot_decide(): void
    {
        $m = SpecMismatch::factory()->for($this->project)->create();
        $this->actingAs(User::factory()->create());

        $this->get("/projects/{$this->project->id}/mismatches")->assertForbidden();

        // Even with the component opened by a member, a decide call from someone else is refused.
        $component = Livewire::actingAs($this->user)->test('mismatches-index', ['project' => $this->project]);
        $this->actingAs(User::factory()->create());
        $component->call('decide', $m->id, 'follow_prd')->assertForbidden();
        $this->assertSame('open', $m->fresh()->status->value);
    }
}
