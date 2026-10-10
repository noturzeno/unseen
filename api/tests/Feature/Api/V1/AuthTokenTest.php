<?php

namespace Tests\Feature\Api\V1;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTokenTest extends TestCase
{
    use RefreshDatabase;

    public function test_valid_credentials_issue_a_token_that_authenticates(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $token = $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email,
            'password' => 'correct-horse-battery',
            'token_name' => 'unseen-cli',
        ])->assertCreated()
            ->assertJsonPath('token_type', 'Bearer')
            ->json('token');

        $this->withToken($token)->getJson('/api/v1/me')
            ->assertOk()
            ->assertJsonPath('data.id', $user->id)
            ->assertJsonMissingPath('data.password');
    }

    public function test_invalid_credentials_are_rejected(): void
    {
        $user = User::factory()->create(['password' => 'correct-horse-battery']);

        $this->postJson('/api/v1/auth/tokens', [
            'email' => $user->email,
            'password' => 'wrong',
            'token_name' => 'cli',
        ])->assertUnprocessable()
            ->assertJsonValidationErrors('email')
            ->assertJsonMissingPath('token');
    }

    public function test_token_can_be_revoked(): void
    {
        $user = User::factory()->create();
        $token = $user->createToken('cli')->plainTextToken;

        $this->withToken($token)->deleteJson('/api/v1/auth/tokens/current')->assertNoContent();

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_create_user_command(): void
    {
        $this->artisan('unseen:create-user', ['email' => 'dev@example.com', '--name' => 'Dev'])
            ->expectsQuestion('Password (min 12 characters)', 'a-long-password')
            ->assertSuccessful();

        $this->assertDatabaseHas('users', ['email' => 'dev@example.com', 'name' => 'Dev']);
    }

    public function test_create_user_command_rejects_short_password(): void
    {
        $this->artisan('unseen:create-user', ['email' => 'dev@example.com', '--name' => 'Dev'])
            ->expectsQuestion('Password (min 12 characters)', 'short')
            ->assertFailed();

        $this->assertDatabaseMissing('users', ['email' => 'dev@example.com']);
    }
}
