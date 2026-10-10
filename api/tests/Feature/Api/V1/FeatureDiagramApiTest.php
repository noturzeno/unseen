<?php

namespace Tests\Feature\Api\V1;

use App\Livewire\FeatureDetail;
use App\Models\Feature;
use App\Models\FeatureDiagram;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Exceptions\MethodNotFoundException;
use Livewire\Livewire;
use Tests\TestCase;

class FeatureDiagramApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Feature $feature;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->feature = Feature::factory()->for(Project::factory()->for($this->user, 'owner'))->create(['name' => 'Checkout']);
    }

    private function token(array $abilities): string
    {
        return $this->user->createToken('t', $abilities)->plainTextToken;
    }

    public function test_agent_token_adds_and_updates_diagrams_as_agent_reported(): void
    {
        $token = $this->token(['read', 'report']);

        $id = $this->withToken($token)->postJson("/api/v1/features/{$this->feature->id}/diagrams", [
            'type' => 'sequence',
            'title' => 'Payment',
            'definition' => "sequenceDiagram\n    User->>API: Pay",
        ])->assertCreated()
            ->assertJsonPath('data.type', 'sequence')
            ->assertJsonPath('data.source', 'agent_reported')
            ->json('data.id');

        $this->withToken($token)->patchJson("/api/v1/diagrams/{$id}", ['title' => 'Card payment'])
            ->assertOk()->assertJsonPath('data.title', 'Card payment')->assertJsonPath('data.type', 'sequence');

        $this->withToken($token)->getJson("/api/v1/features/{$this->feature->id}/diagrams")
            ->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.definition', "sequenceDiagram\n    User->>API: Pay");

        // Deleting is a write action.
        $this->withToken($token)->deleteJson("/api/v1/diagrams/{$id}")->assertForbidden();
        $this->app['auth']->forgetGuards();
        $this->withToken($this->token(['write']))->deleteJson("/api/v1/diagrams/{$id}")->assertNoContent();
        $this->assertDatabaseCount('feature_diagrams', 0);
    }

    public function test_write_token_diagrams_are_agent_reported_and_input_is_validated(): void
    {
        $token = $this->token(['write']);

        $this->withToken($token)->postJson("/api/v1/features/{$this->feature->id}/diagrams", [
            'type' => 'use_case', 'title' => 'Who uses it', 'definition' => 'flowchart LR',
        ])->assertCreated()->assertJsonPath('data.source', 'agent_reported');

        $this->withToken($token)->postJson("/api/v1/features/{$this->feature->id}/diagrams", [
            'type' => 'gantt', 'title' => '', 'definition' => str_repeat('x', FeatureDiagram::MAX_DEFINITION + 1),
        ])->assertUnprocessable()->assertJsonValidationErrors(['type', 'title', 'definition']);
    }

    public function test_read_only_tokens_and_outsiders_cannot_change_diagrams(): void
    {
        $diagram = FeatureDiagram::factory()->for($this->feature)->create();

        $this->withToken($this->token(['read']))->postJson("/api/v1/features/{$this->feature->id}/diagrams", [
            'type' => 'flowchart', 'title' => 'x', 'definition' => 'flowchart TD',
        ])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $outsider = User::factory()->create()->createToken('t')->plainTextToken;
        $this->withToken($outsider)->getJson("/api/v1/features/{$this->feature->id}/diagrams")->assertForbidden();
        $this->withToken($outsider)->patchJson("/api/v1/diagrams/{$diagram->id}", ['title' => 'x'])->assertForbidden();
        $this->withToken($outsider)->deleteJson("/api/v1/diagrams/{$diagram->id}")->assertForbidden();
    }

    public function test_feature_page_shows_diagrams_read_only(): void
    {
        FeatureDiagram::factory()->for($this->feature)->create(['title' => 'Checkout sequence', 'definition' => 'sequenceDiagram']);
        $this->actingAs($this->user);

        $this->get("/features/{$this->feature->id}")
            ->assertOk()
            ->assertSee('Checkout sequence')
            ->assertSee('data-definition="sequenceDiagram"', false)
            ->assertDontSee('newDiagram')
            ->assertDontSee('saveDiagram');

        // The editing actions no longer exist, so they can't be called directly either.
        $this->expectException(MethodNotFoundException::class);
        Livewire::test(FeatureDetail::class, ['feature' => $this->feature])
            ->call('deleteDiagram', $this->feature->diagrams()->value('id'));
    }
}
