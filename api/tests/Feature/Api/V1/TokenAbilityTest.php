<?php

namespace Tests\Feature\Api\V1;

use App\Models\Feature;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenAbilityTest extends TestCase
{
    use RefreshDatabase;

    public function test_token_can_be_limited_to_read_and_report(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);
        $project = Project::factory()->for($user, 'owner')->create();
        $feature = Feature::factory()->for($project)->create();

        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
            'token_name' => 'unseen-cli',
            'abilities' => ['read', 'report'],
        ])->assertCreated()->assertJsonPath('abilities', ['read', 'report']);

        $token = $response->json('token');
        $this->app['auth']->forgetGuards();

        $this->withToken($token)->getJson("/api/v1/projects/{$project->id}/dashboard")->assertOk();
        $this->withToken($token)->getJson("/api/v1/projects/{$project->id}/features")->assertOk();
        $this->withToken($token)->postJson("/api/v1/projects/{$project->id}/reports", ['summary' => 'From CLI'])->assertCreated();

        $this->withToken($token)->patchJson("/api/v1/features/{$feature->id}", ['status' => 'implemented'])->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'x'])->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/projects', ['name' => 'x'])->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/projects/{$project->id}/archive")->assertForbidden();
    }

    public function test_read_only_token_cannot_submit_reports(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        $token = $user->createToken('ro', ['read'])->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/projects/{$project->id}")->assertOk();
        $this->withToken($token)->postJson("/api/v1/projects/{$project->id}/reports", ['summary' => 'x'])->assertForbidden();
        $this->assertDatabaseCount('development_reports', 0);
    }

    public function test_default_token_has_all_abilities(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email, 'password' => 'correct-horse-battery', 'token_name' => 'full',
        ])->assertCreated()->assertJsonPath('abilities', ['read', 'report', 'record', 'write', 'verify']);
    }

    public function test_unknown_abilities_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email, 'password' => 'correct-horse-battery', 'token_name' => 'x', 'abilities' => ['admin'],
        ])->assertUnprocessable()->assertJsonValidationErrors('abilities.0');
    }

    public function test_project_policy_still_applies_to_limited_tokens(): void
    {
        $user = User::factory()->create();
        $foreign = Project::factory()->create();
        $token = $user->createToken('cli', ['read', 'report'])->plainTextToken;

        $this->withToken($token)->getJson("/api/v1/projects/{$foreign->id}")->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/projects/{$foreign->id}/reports", ['summary' => 'x'])->assertForbidden();
    }

    public function test_agent_token_records_tracking_data_but_not_projects_members_or_tokens(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->for($user, 'owner')->create();
        $colleague = User::factory()->create();
        $token = $user->createToken('agent', ['read', 'report', 'record'])->plainTextToken;

        // Tracking data: allowed.
        $categoryId = $this->withToken($token)->postJson("/api/v1/projects/{$project->id}/categories", ['name' => 'Billing'])
            ->assertCreated()->json('data.id');
        $featureId = $this->withToken($token)->postJson("/api/v1/projects/{$project->id}/features", [
            'name' => 'Invoices', 'category_id' => $categoryId, 'status' => 'implemented', 'acceptance_criteria' => ['Download as PDF'],
        ])->assertCreated()->json('data.id');
        $this->withToken($token)->postJson("/api/v1/features/{$featureId}/checks", ['pattern' => 'Tests\\Feature\\InvoiceTest'])->assertCreated();
        $this->withToken($token)->patchJson("/api/v1/features/{$featureId}", ['status' => 'in_progress'])->assertOk();

        // People's business: refused.
        $this->withToken($token)->postJson("/api/v1/projects/{$project->id}/members", ['email' => $colleague->email])->assertForbidden();
        $this->withToken($token)->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'])->assertForbidden();
        $this->withToken($token)->postJson("/api/v1/projects/{$project->id}/archive")->assertForbidden();
        $this->withToken($token)->postJson('/api/v1/projects', ['name' => 'New'])->assertForbidden();
        $this->withToken($token)->getJson('/api/v1/auth/tokens')->assertForbidden();
        $this->withToken($token)->deleteJson('/api/v1/auth/tokens/1')->assertForbidden();

        // And still never verification.
        $this->withToken($token)->patchJson("/api/v1/features/{$featureId}", ['verification_status' => 'verified'])->assertUnprocessable();
        $this->withToken($token)->postJson("/api/v1/projects/{$project->id}/test-runs", [])->assertForbidden();

        $this->assertFalse($project->fresh()->hasMember($colleague));
        $this->assertNotSame('Renamed', $project->fresh()->name);
    }
}
