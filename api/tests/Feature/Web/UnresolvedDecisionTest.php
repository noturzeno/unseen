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

    public function test_ignoring_closes_the_item_with_its_reason_and_keeps_it_from_the_agent(): void
    {
        $item = $this->item();

        Livewire::test(UnresolvedIndex::class, ['project' => $this->project])
            ->assertSee('Ignore')
            ->call('ignore', $item->id)
            ->assertHasErrors(["decisions.{$item->id}" => 'required'])
            ->set("decisions.{$item->id}", 'Not needed: leave the ad unlock as it is.')
            ->call('ignore', $item->id)
            ->assertHasNoErrors()
            ->assertDontSee('A Basic member can unlock')
            ->set('status', 'ignored')
            ->assertSee('A Basic member can unlock')
            ->assertSee('Reason: Not needed: leave the ad unlock as it is.');

        $item->refresh();
        $this->assertSame([UnresolvedItem::IGNORED, $this->user->id, 'Not needed: leave the ad unlock as it is.'], [$item->status, $item->resolved_by, $item->resolution_note]);
        $this->assertFalse(NextSteps::for($this->project)->contains(fn ($s) => in_array($s['kind'], ['unresolved', 'defect', 'sort'], true)));

        // Agents can't resolve or reopen it; a person can reopen it.
        Sanctum::actingAs($this->user, ['*']);
        $this->postJson("/api/v1/unresolved/{$item->id}/reopen")->assertUnprocessable();
        $this->postJson("/api/v1/projects/{$this->project->id}/reports", ['summary' => 'Done', 'resolves' => [$item->id]])->assertCreated();
        $this->assertSame(UnresolvedItem::IGNORED, $item->fresh()->status);

        Livewire::test(UnresolvedIndex::class, ['project' => $this->project])->set('status', 'ignored')->call('reopen', $item->id);
        $this->assertTrue($item->fresh()->isOpen());
    }

    public function test_every_open_item_has_an_ignore_button(): void
    {
        foreach ([WaitingOn::Team, WaitingOn::Client, WaitingOn::Agent, null] as $waiting) {
            $this->project->unresolvedItems()->create(['text' => 'Item '.($waiting?->value ?? 'unsorted'), 'status' => UnresolvedItem::OPEN, 'waiting_on' => $waiting]);
        }

        $html = $this->get("/projects/{$this->project->id}/unresolved")->assertOk()->getContent();
        $this->assertSame(4, substr_count($html, '>Ignore</button>'));
    }

    public function test_people_who_cant_edit_the_project_cant_decide(): void
    {
        $item = $this->item();
        $this->actingAs(User::factory()->create());

        Livewire::test(UnresolvedIndex::class, ['project' => $this->project])->assertForbidden();
        $this->assertNull($item->fresh()->decision);
    }
}
