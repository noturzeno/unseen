<?php

namespace Tests\Feature\Api\V1;

use App\Models\Feature;
use App\Models\Project;
use App\Models\SpecMismatch;
use App\Models\User;
use App\Services\AgentRunService;
use App\Support\NextSteps;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class SpecMismatchApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private const AGENT = ['read', 'report', 'record'];

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Sanctum::actingAs(User::factory()->create(), self::AGENT);
        $this->project = Project::factory()->for($this->user, 'owner')->create();
    }

    private function payload(array $overrides = []): array
    {
        return [
            'key' => 'PRM-01-list', 'reference' => 'PRD §4.11 PRM-01', 'title' => 'Promo codes: list vs text box',
            'prd_says' => 'Users view available codes.', 'design_says' => 'A single text box to type a code.',
            'built_to' => 'design', 'built_note' => 'Text box only', 'impact' => 'One more API call', 'decide_by' => 'client',
            ...$overrides,
        ];
    }

    public function test_agent_records_and_rerecording_the_same_key_updates(): void
    {
        $feature = Feature::factory()->for($this->project)->create();

        $id = $this->postJson("/api/v1/projects/{$this->project->id}/mismatches", $this->payload(['feature_id' => $feature->id]))
            ->assertCreated()
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.decide_by', 'client')
            ->assertJsonPath('data.built_to', 'design')
            ->assertJsonPath('data.feature.id', $feature->id)
            ->json('data.id');

        $this->postJson("/api/v1/projects/{$this->project->id}/mismatches", $this->payload(['design_says' => 'Text box with Apply']))
            ->assertOk()->assertJsonPath('data.id', $id)->assertJsonPath('data.design_says', 'Text box with Apply');
        $this->assertSame(1, SpecMismatch::count());

        $this->postJson("/api/v1/projects/{$this->project->id}/mismatches", ['title' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors(['prd_says', 'design_says']);
        $this->postJson("/api/v1/projects/{$this->project->id}/mismatches", $this->payload(['key' => 'k2', 'decide_by' => 'agent']))
            ->assertUnprocessable()->assertJsonValidationErrors('decide_by');
    }

    public function test_agents_cannot_decide_and_cannot_apply_an_undecided_mismatch(): void
    {
        $m = SpecMismatch::factory()->for($this->project)->create();

        $this->postJson("/api/v1/mismatches/{$m->id}/decide", ['decision' => 'follow_design'])->assertForbidden();
        $this->postJson("/api/v1/mismatches/{$m->id}/applied")->assertUnprocessable()->assertJsonValidationErrors('status');
        $this->assertSame('open', $m->fresh()->status->value);
    }

    public function test_person_decides_then_agent_applies(): void
    {
        $m = SpecMismatch::factory()->for($this->project)->create();

        Sanctum::actingAs($this->user, ['read', 'write']);
        $this->postJson("/api/v1/mismatches/{$m->id}/decide", ['decision' => 'other'])
            ->assertUnprocessable()->assertJsonValidationErrors('decision_note');
        $this->postJson("/api/v1/mismatches/{$m->id}/decide", ['decision' => 'follow_prd', 'decision_note' => 'Client signed the PRD'])
            ->assertOk()
            ->assertJsonPath('data.status', 'decided')
            ->assertJsonPath('data.decision', 'follow_prd')
            ->assertJsonPath('data.decided_by.id', $this->user->id)
            ->assertJsonPath('data.next_action', 'Change the product to match the PRD.');

        Sanctum::actingAs($this->user, self::AGENT);
        $this->getJson("/api/v1/projects/{$this->project->id}/dashboard")
            ->assertJsonPath('data.summary.mismatches.decided', 1)
            ->assertJsonPath('data.mismatches.0.id', $m->id);

        $this->postJson("/api/v1/mismatches/{$m->id}/applied", ['note' => 'List added'])->assertOk()
            ->assertJsonPath('data.status', 'applied')->assertJsonPath('data.applied_note', 'List added');

        // Re-recording later doesn't undo the decision.
        $m->update(['key' => 'k']);
        $this->postJson("/api/v1/projects/{$this->project->id}/mismatches", $this->payload(['key' => 'k']))->assertOk()
            ->assertJsonPath('data.status', 'applied')->assertJsonPath('data.decision', 'follow_prd');

        Sanctum::actingAs($this->user, ['write']);
        $this->postJson("/api/v1/mismatches/{$m->id}/decide", ['decision' => 'follow_design'])->assertUnprocessable();
    }

    public function test_outsiders_cannot_see_or_change(): void
    {
        $m = SpecMismatch::factory()->for($this->project)->create();
        $foreignFeature = Feature::factory()->create();

        $this->postJson("/api/v1/projects/{$this->project->id}/mismatches", $this->payload(['feature_id' => $foreignFeature->id]))
            ->assertUnprocessable()->assertJsonValidationErrors('feature_id');

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJson("/api/v1/mismatches/{$m->id}")->assertForbidden();
        $this->getJson("/api/v1/projects/{$this->project->id}/mismatches")->assertForbidden();
        $this->postJson("/api/v1/mismatches/{$m->id}/decide", ['decision' => 'follow_prd'])->assertForbidden();
    }

    public function test_prompts_and_next_step(): void
    {
        $find = app(AgentRunService::class)->queue($this->project, $this->user, 'find_mismatches');
        $this->assertStringContainsString('unseen mismatch add --file', $find->prompt);
        $this->assertStringContainsString('Never decide a mismatch yourself', $find->prompt);

        $this->assertFalse(NextSteps::for($this->project)->contains('kind', 'mismatches'));

        SpecMismatch::factory()->for($this->project)->create(['title' => 'Plan first', 'status' => 'decided', 'decision' => 'follow_design', 'decided_at' => now()]);
        $step = NextSteps::for($this->project)->firstWhere('kind', 'mismatches');
        $this->assertSame('Apply 1 PRD vs design decision', $step['title']);
        $this->assertStringContainsString('Decision: Keep the design. Keep the product as designed and update the PRD to match.', $step['prompt']);
        $this->assertStringContainsString('unseen mismatch applied <id>', $step['prompt']);
        $this->assertStringContainsString('Never put secrets', $step['prompt']);
    }
}
