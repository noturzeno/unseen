<?php

namespace Tests\Feature\Web;

use App\Models\Project;
use App\Models\UnresolvedItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class UnresolvedWaitingOnUiTest extends TestCase
{
    use RefreshDatabase;

    public function test_items_are_grouped_by_who_has_to_act(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create(['name' => 'SGC']);
        $this->actingAs($user);

        UnresolvedItem::factory()->for($project)->create(['text' => 'Fix the 500 on payments', 'waiting_on' => 'agent']);
        UnresolvedItem::factory()->for($project)->create(['text' => 'Unsorted gap']);
        UnresolvedItem::factory()->for($project)->create(['text' => 'SingPass integration', 'waiting_on' => 'client', 'decision_note' => 'Client to provide SingPass access']);
        UnresolvedItem::factory()->for($project)->create(['text' => 'OneMap amenities', 'waiting_on' => 'team', 'decision_note' => 'Get a free OneMap API token']);

        $this->get("/projects/{$project->id}/unresolved")->assertOk()
            ->assertSeeInOrder(['Waiting on you', 'Get a free OneMap API token', 'Waiting on the client', 'Client to provide SingPass access',
                'Not sorted yet', 'Unsorted gap', 'Your coding agent can do these', 'Fix the 500 on payments'])
            ->assertSee('Your decision needed')->assertSee('Client decision needed')
            ->assertSee('Copy message for client')->assertSee('Copy prompt with decision')
            ->assertSee('Ask your agent which need a decision from you or the client');

        Livewire::test('unresolved-index', ['project' => $project])
            ->set('waiting', 'client')->assertViewHas('items', fn ($items) => $items->pluck('text')->all() === ['SingPass integration'])
            ->set('waiting', 'unsorted')->assertViewHas('items', fn ($items) => $items->pluck('text')->all() === ['Unsorted gap'])
            ->set('waiting', 'bogus')->assertViewHas('items', fn ($items) => $items->count() === 4);

        $this->get("/projects/{$project->id}")->assertOk()
            ->assertSee('1 need your decision')->assertSee('1 need the client')->assertSee('Get a free OneMap API token');
    }
}
