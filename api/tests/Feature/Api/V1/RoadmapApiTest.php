<?php

namespace Tests\Feature\Api\V1;

use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class RoadmapApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user, 'owner')->create();
        $this->token = $this->user->createToken('agent', ['read', 'report', 'record'])->plainTextToken;
    }

    private function sync(array $phases)
    {
        return $this->withToken($this->token)->putJson("/api/v1/projects/{$this->project->id}/roadmap", ['phases' => $phases]);
    }

    public function test_agent_syncs_phases_with_implementation_lists(): void
    {
        $login = Feature::factory()->for($this->project)->withStatus(FeatureStatus::Implemented, VerificationStatus::Verified)->create(['name' => 'Login']);
        $billing = Feature::factory()->for($this->project)->withStatus(FeatureStatus::Implemented, VerificationStatus::NeedsVerification)->create();

        $this->sync([
            ['key' => 'M1', 'name' => 'Foundation', 'description' => 'Auth and projects', 'target_date' => '2026-10-01', 'items' => [
                ['text' => 'Sign in', 'done' => true, 'feature_id' => $login->id],
                ['text' => 'Billing page', 'done' => true, 'feature_id' => $billing->id],
            ]],
            ['key' => 'M2', 'name' => 'Reports', 'items' => [
                ['text' => 'Report API', 'done' => true],
                ['text' => 'Report UI'],
            ]],
            ['key' => 'M3', 'name' => 'Polish', 'status' => 'blocked'],
        ])->assertOk()
            ->assertJsonPath('data.phases.0.key', 'M1')
            ->assertJsonPath('data.phases.0.status', 'done') // derived: everything done
            ->assertJsonPath('data.phases.0.target_date', '2026-10-01')
            ->assertJsonPath('data.phases.0.progress', ['items' => 2, 'done' => 2, 'percent' => 100, 'features' => 2, 'implemented' => 2, 'verified' => 1])
            ->assertJsonPath('data.phases.0.items.0.feature.name', 'Login')
            ->assertJsonPath('data.phases.1.status', 'in_progress') // derived: some done
            ->assertJsonPath('data.phases.1.progress.percent', 50)
            ->assertJsonPath('data.phases.2.status', 'blocked'); // given explicitly

        $this->assertNotNull($this->project->fresh()->roadmap_synced_at);
        $this->assertSame('agent_reported', $this->project->roadmapPhases()->first()->source->value);
    }

    public function test_sync_matches_phases_by_key_and_removes_phases_not_sent(): void
    {
        $this->sync([
            ['key' => 'M1', 'name' => 'Foundation', 'items' => [['text' => 'A'], ['text' => 'B']]],
            ['key' => 'M2', 'name' => 'Reports'],
        ])->assertOk();
        $m1 = $this->project->roadmapPhases()->where('key', 'M1')->value('id');

        $this->sync([
            ['key' => 'm2', 'name' => 'Reports v2'], // keys match case-insensitively
            ['key' => 'M1', 'name' => 'Foundation', 'items' => [['text' => 'A', 'done' => true]]],
        ])->assertOk()
            ->assertJsonCount(2, 'data.phases')
            ->assertJsonPath('data.phases.0.name', 'Reports v2')
            ->assertJsonPath('data.phases.1.items.0.done', true)
            ->assertJsonCount(1, 'data.phases.1.items');

        $this->assertSame($m1, $this->project->roadmapPhases()->where('key', 'M1')->value('id'), 'same phase row kept');

        $this->sync([])->assertOk()->assertJsonCount(0, 'data.phases');
        $this->assertDatabaseCount('roadmap_items', 0);
    }

    public function test_sync_is_validated_and_all_or_nothing(): void
    {
        $foreign = Feature::factory()->create();
        $this->sync([['key' => 'M1', 'name' => 'Keep me']])->assertOk();

        $this->sync([
            ['key' => 'X', 'name' => 'Dup'],
            ['key' => 'x', 'name' => 'Dup again'],
            ['key' => 'Y', 'name' => '', 'status' => 'shipped', 'target_date' => 'next week', 'items' => [
                ['text' => 'Uses another project feature', 'feature_id' => $foreign->id],
            ]],
        ])->assertUnprocessable()->assertJsonValidationErrors([
            'phases.1.key', 'phases.2.name', 'phases.2.status', 'phases.2.target_date', 'phases.2.items.0.feature_id',
        ]);

        $this->withToken($this->token)->putJson("/api/v1/projects/{$this->project->id}/roadmap", [])
            ->assertUnprocessable()->assertJsonValidationErrors('phases');

        $this->assertSame(['M1'], $this->project->roadmapPhases()->pluck('key')->all(), 'nothing changed');
    }

    public function test_permissions(): void
    {
        $read = $this->user->createToken('ro', ['read'])->plainTextToken;
        $this->withToken($read)->getJson("/api/v1/projects/{$this->project->id}/roadmap")->assertOk()->assertJsonPath('data.phases', []);
        $this->withToken($read)->putJson("/api/v1/projects/{$this->project->id}/roadmap", ['phases' => []])->assertForbidden();

        $this->app['auth']->forgetGuards();
        $outsider = User::factory()->create()->createToken('t')->plainTextToken;
        $this->withToken($outsider)->getJson("/api/v1/projects/{$this->project->id}/roadmap")->assertForbidden();
        $this->withToken($outsider)->putJson("/api/v1/projects/{$this->project->id}/roadmap", ['phases' => []])->assertForbidden();
    }

    public function test_roadmap_shows_on_the_dashboard(): void
    {
        $feature = Feature::factory()->for($this->project)->withStatus(FeatureStatus::Implemented, VerificationStatus::NeedsVerification)->create(['name' => 'Exports']);
        $this->sync([
            ['key' => 'M1', 'name' => 'Foundation', 'target_date' => '2020-01-01', 'items' => [['text' => 'CSV export', 'feature_id' => $feature->id, 'done' => true]], 'status' => 'done'],
            ['key' => 'M2', 'name' => 'Later phase', 'target_date' => '2020-01-01', 'items' => [['text' => 'Something']]],
        ])->assertOk();
        $this->app['auth']->forgetGuards();
        $this->actingAs($this->user);

        $this->get("/projects/{$this->project->id}")->assertOk()->assertSee('Roadmap')->assertSee('Later phase');
        $this->get("/projects/{$this->project->id}/roadmap")->assertOk()
            ->assertSee('Foundation')
            ->assertSee('CSV export')
            ->assertSee('Exports')
            ->assertSee('Done, not all verified')
            ->assertSee('Overdue'); // M2 is past its date and not done
    }
}
