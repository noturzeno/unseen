<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TokenManagementApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_tokens_can_expire(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $response = $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email, 'password' => 'correct-horse-battery', 'token_name' => 'cli', 'abilities' => ['read'], 'expires_in_days' => 30,
        ])->assertCreated();
        $this->assertEqualsWithDelta(now()->addDays(30)->timestamp, strtotime($response->json('expires_at')), 5);

        $token = $response->json('token');
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertOk();

        $this->travel(31)->days();
        $this->app['auth']->forgetGuards();
        $this->withToken($token)->getJson('/api/v1/me')->assertUnauthorized();
    }

    public function test_expiry_is_validated(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email, 'password' => 'correct-horse-battery', 'token_name' => 'x', 'expires_in_days' => 999,
        ])->assertUnprocessable()->assertJsonValidationErrors('expires_in_days');
    }

    public function test_list_and_revoke_own_tokens_with_write_ability(): void
    {
        $user = User::factory()->create();
        $admin = $user->createToken('admin', ['read', 'write'])->plainTextToken;
        $cli = $user->createToken('cli', ['read', 'report']);
        $otherUsersToken = User::factory()->create()->createToken('theirs');

        $this->withToken($admin)->getJson('/api/v1/auth/tokens')
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonMissingPath('data.0.token')
            ->assertJsonFragment(['name' => 'admin', 'current' => true]);

        $this->withToken($admin)->deleteJson("/api/v1/auth/tokens/{$otherUsersToken->accessToken->id}")->assertNotFound();
        $this->withToken($admin)->deleteJson("/api/v1/auth/tokens/{$cli->accessToken->id}")->assertNoContent();
        $this->assertDatabaseMissing('personal_access_tokens', ['id' => $cli->accessToken->id]);
        $this->assertDatabaseHas('personal_access_tokens', ['id' => $otherUsersToken->accessToken->id]);
    }

    public function test_agent_tokens_cannot_list_or_revoke_tokens(): void
    {
        $user = User::factory()->create();
        $cli = $user->createToken('cli', ['read', 'report'])->plainTextToken;
        $ci = $user->createToken('ci', ['read', 'verify']);

        $this->withToken($cli)->getJson('/api/v1/auth/tokens')->assertForbidden();
        $this->withToken($cli)->deleteJson("/api/v1/auth/tokens/{$ci->accessToken->id}")->assertForbidden();
    }

    public function test_expired_tokens_are_pruned_by_schedule_command(): void
    {
        $user = User::factory()->create();
        $user->createToken('old', ['read'], now()->subDays(3));
        $user->createToken('fresh', ['read'], now()->addDays(3));

        $this->artisan('sanctum:prune-expired', ['--hours' => 24])->assertSuccessful();

        $this->assertDatabaseCount('personal_access_tokens', 1);
    }
}
