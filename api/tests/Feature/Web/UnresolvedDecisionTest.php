<?php

namespace Tests\Feature\Web;

use App\Enums\WaitingOn;
use App\Livewire\UnresolvedIndex;
use App\Models\Project;
use App\Models\UnresolvedItem;
use App\Models\User;
use App\Services\UnresolvedItemService;
use App\Support\NextSteps;
use App\Support\UnresolvedPrompt;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

class UnresolvedDecisionTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create(['name' => 'Zeno']);
        $this->project = Project::factory()->for($this->user, 'owner')->create();
        $this->actingAs($this->user);
    }

    private function item(WaitingOn $waiting = WaitingOn::Team): UnresolvedItem
    {
        return $this->project->unresolvedItems()->create([
            'text' => 'A Basic member can unlock a Plus area with a rewarded ad.',
            'status' => UnresolvedItem::OPEN,
            'waiting_on' => $waiting,
            'decision_note' => 'Decide whether to add the ad unlock to the criteria or remove it.',
        ]);
    }

    public function test_a_decision_made_in_the_ui_is_saved_and_put_in_the_prompt(): void
    {
        $item = $this->item();
        $this->assertStringContainsString('The decision: ___', UnresolvedPrompt::forItem($item));

        Livewire::test(UnresolvedIndex::class, ['project' => $this->project])
            ->assertSee('Decide')
            ->call('decide', $item->id)
            ->assertHasErrors(["decisions.{$item->id}" => 'required'])
            ->set("decisions.{$item->id}", 'Keep the ad unlock and add it to the criteria.')
            ->call('decide', $item->id)
            ->assertHasNoErrors()
            ->assertDispatched('decided', id: $item->id)
            ->assertSee('Decided:')
            ->assertSee('Keep the ad unlock and add it to the criteria.');

        $item->refresh();
        $this->assertSame([WaitingOn::Agent, 'Keep the ad unlock and add it to the criteria.', $this->user->id], [$item->waiting_on, $item->decision, $item->decided_by]);
        $this->assertNotNull($item->decided_at);
        $this->assertFalse($item->needsDecision());

        $prompt = UnresolvedPrompt::forItem($item->fresh());
        $this->assertStringContainsString("Zeno decided (Decide whether to add the ad unlock to the criteria or remove it.):\nKeep the ad unlock and add it to the criteria.", $prompt);
        $this->assertStringNotContainsString('___', $prompt);

        $all = UnresolvedPrompt::forAll($this->project, $this->project->unresolvedItems()->get(), 1);
        $this->assertStringContainsString('Decided (Zeno): Keep the ad unlock and add it to the criteria.', $all);

        // Now the agent can do it: it is offered as a next step.
        $this->assertTrue(NextSteps::for($this->project)->contains('kind', 'unresolved'));
    }

    public function test_the_api_shows_the_decision_to_agents(): void
    {
        $item = $this->item(WaitingOn::Client);
        app(UnresolvedItemService::class)->decide($item, 'Client says: remove it.', $this->user);
        Sanctum::actingAs($this->user, ['*']);

        $this->getJson("/api/v1/projects/{$this->project->id}/unresolved?waiting_on=agent")
            ->assertOk()->assertJsonPath('data.0.decision', 'Client says: remove it.');
    }

    public function test_people_who_cant_edit_the_project_cant_decide(): void
    {
        $item = $this->item();
        $this->actingAs(User::factory()->create());

        Livewire::test(UnresolvedIndex::class, ['project' => $this->project])->assertForbidden();
        $this->assertNull($item->fresh()->decision);
    }
}
