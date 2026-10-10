<?php

namespace Tests\Feature\Api\V1;

use App\Models\Project;
use App\Models\UnresolvedItem;
use App\Models\User;
use App\Services\AgentRunService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnresolvedWaitingOnTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Sanctum::actingAs(User::factory()->create(), ['read', 'report', 'record']);
        $this->project = Project::factory()->for($this->user, 'owner')->create();
    }

    public function test_reports_can_say_who_an_open_item_waits_on(): void
    {
        $this->postJson("/api/v1/projects/{$this->project->id}/reports", ['summary' => 'x', 'unresolved' => [
            'Plain string still works',
            ['text' => 'SingPass integration (BR-02)', 'waiting_on' => 'client', 'decision_note' => 'Client to provide SingPass credentials'],
            ['text' => 'Map amenities', 'waiting_on' => 'team', 'extra' => 'dropped'],
        ]])->assertCreated()->assertJsonPath('data.unresolved', ['Plain string still works', 'SingPass integration (BR-02)', 'Map amenities']);

        $items = $this->getJson("/api/v1/projects/{$this->project->id}/unresolved")->json('data');
        $byText = collect($items)->keyBy('text');
        $this->assertNull($byText['Plain string still works']['waiting_on']);
        $this->assertSame('client', $byText['SingPass integration (BR-02)']['waiting_on']);
        $this->assertSame('Client to provide SingPass credentials', $byText['SingPass integration (BR-02)']['decision_note']);
        $this->assertSame('team', $byText['Map amenities']['waiting_on']);

        $this->getJson("/api/v1/projects/{$this->project->id}/unresolved?waiting_on=unsorted")->assertJsonCount(1, 'data');
        $this->getJson("/api/v1/projects/{$this->project->id}/unresolved?waiting_on=client")->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/projects/{$this->project->id}/reports", ['summary' => 'x', 'unresolved' => [['text' => 'y', 'waiting_on' => 'boss']]])
            ->assertUnprocessable()->assertJsonValidationErrors('unresolved.0.waiting_on');
        $this->postJson("/api/v1/projects/{$this->project->id}/reports", ['summary' => 'x', 'unresolved' => [['waiting_on' => 'client']]])
            ->assertUnprocessable()->assertJsonValidationErrors('unresolved.0.text');
    }

    public function test_the_agent_sorts_existing_items(): void
    {
        $item = UnresolvedItem::factory()->for($this->project)->create();

        $this->patchJson("/api/v1/unresolved/{$item->id}", ['waiting_on' => 'client', 'decision_note' => 'Confirm the audience model'])
            ->assertOk()->assertJsonPath('data.waiting_on', 'client')->assertJsonPath('data.decision_note', 'Confirm the audience model');
        $this->patchJson("/api/v1/unresolved/{$item->id}", ['waiting_on' => null])->assertOk()->assertJsonPath('data.waiting_on', null);
        $this->patchJson("/api/v1/unresolved/{$item->id}", [])->assertUnprocessable()->assertJsonValidationErrors('waiting_on');

        Sanctum::actingAs($this->user, ['read', 'report']);
        $this->patchJson("/api/v1/unresolved/{$item->id}", ['waiting_on' => 'team'])->assertForbidden();
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->patchJson("/api/v1/unresolved/{$item->id}", ['waiting_on' => 'team'])->assertForbidden();
    }

    public function test_dashboard_counts_and_orders_by_who_has_to_act(): void
    {
        UnresolvedItem::factory()->for($this->project)->create(['text' => 'agent one', 'waiting_on' => 'agent']);
        UnresolvedItem::factory()->for($this->project)->create(['text' => 'unsorted one']);
        UnresolvedItem::factory()->for($this->project)->create(['text' => 'client one', 'waiting_on' => 'client']);
        UnresolvedItem::factory()->for($this->project)->create(['text' => 'team one', 'waiting_on' => 'team']);

        $this->getJson("/api/v1/projects/{$this->project->id}/dashboard")->assertOk()
            ->assertJsonPath('data.summary.unresolved_waiting_on', ['client' => 1, 'team' => 1, 'agent' => 1, 'unsorted' => 1])
            ->assertJsonPath('data.unresolved.0.text', 'team one')
            ->assertJsonPath('data.unresolved.1.text', 'client one')
            ->assertJsonPath('data.unresolved.2.text', 'unsorted one')
            ->assertJsonPath('data.unresolved.3.text', 'agent one');
    }

    public function test_sort_run_asks_the_agent_to_classify_and_split(): void
    {
        UnresolvedItem::factory()->for($this->project)->create(['text' => 'Partial gaps: BR-02 SingPass; PAY-03 undefined']);

        $run = app(AgentRunService::class)->queue($this->project, $this->user, 'sort');

        $this->assertSame('Sort open items by who has to act', $run->title);
        $this->assertStringContainsString('unseen api PATCH /unresolved/<id>', $run->prompt);
        $this->assertStringContainsString('split it', $run->prompt);
        $this->assertStringContainsString('Partial gaps: BR-02 SingPass', $run->prompt);
    }
}
