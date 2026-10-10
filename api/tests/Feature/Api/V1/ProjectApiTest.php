<?php

namespace Tests\Feature\Api\V1;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class ProjectApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_cannot_access_projects(): void
    {
        $project = Project::factory()->create();

        $this->getJson('/api/v1/projects')->assertUnauthorized();
        $this->postJson('/api/v1/projects', ['name' => 'X'])->assertUnauthorized();
        $this->getJson("/api/v1/projects/{$project->id}")->assertUnauthorized();
        $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'X'])->assertUnauthorized();
    }

    public function test_user_can_create_a_project(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/projects', [
            'name' => 'Client portal',
            'description' => 'Customer self-service portal',
            'repository_url' => 'git@github.com:example/portal.git',
            'tech_stack' => 'Laravel, Vue',
        ])
            ->assertCreated()
            ->assertJsonPath('data.name', 'Client portal')
            ->assertJsonPath('data.owner.id', $user->id)
            ->assertJsonPath('data.is_owner', true)
            ->assertJsonPath('data.archived', false);

        $this->assertDatabaseHas('projects', ['name' => 'Client portal', 'owner_id' => $user->id]);
    }

    public function test_create_validates_payload(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/projects', [
            'name' => '',
            'repository_url' => 'not a repo',
            'tech_stack' => str_repeat('x', 256),
        ])->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'repository_url', 'tech_stack']);
    }

    public function test_owner_cannot_be_set_through_payload(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $other = User::factory()->create();

        $this->postJson('/api/v1/projects', ['name' => 'Mine', 'owner_id' => $other->id])
            ->assertCreated()
            ->assertJsonPath('data.owner.id', $user->id);
    }

    /**
     * @return array<string, array{string}>
     */
    public static function repositoryProvider(): array
    {
        return [
            'https' => ['https://github.com/example/repo'],
            'ssh url' => ['ssh://git@example.com/repo.git'],
            'scp style' => ['git@github.com:example/repo.git'],
            'local path' => ['/Users/dev/code/repo'],
        ];
    }

    #[DataProvider('repositoryProvider')]
    public function test_accepts_repository_locations(string $location): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->postJson('/api/v1/projects', ['name' => 'Repo', 'repository_url' => $location])
            ->assertCreated()
            ->assertJsonPath('data.repository_url', $location);
    }

    public function test_index_lists_only_accessible_projects(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $owned = Project::factory()->for($user, 'owner')->create();
        $shared = Project::factory()->create();
        $shared->members()->attach($user);
        $foreign = Project::factory()->create();

        $ids = collect($this->getJson('/api/v1/projects')->assertOk()->json('data'))->pluck('id');

        $this->assertEqualsCanonicalizing([$owned->id, $shared->id], $ids->all());
        $this->assertNotContains($foreign->id, $ids);
    }

    public function test_index_filters_archived_projects(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $active = Project::factory()->for($user, 'owner')->create();
        $archived = Project::factory()->for($user, 'owner')->archived()->create();

        $ids = fn (string $query) => collect($this->getJson("/api/v1/projects{$query}")->assertOk()->json('data'))->pluck('id')->all();

        $this->assertEquals([$active->id], $ids(''));
        $this->assertEquals([$archived->id], $ids('?archived=only'));
        $this->assertEqualsCanonicalizing([$active->id, $archived->id], $ids('?archived=include'));
        $this->getJson('/api/v1/projects?archived=bogus')->assertUnprocessable();
    }

    public function test_user_cannot_view_or_update_someone_elses_project(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->create(['name' => 'Original']);

        $this->getJson("/api/v1/projects/{$project->id}")->assertForbidden();
        $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Hijacked'])->assertForbidden();
        $this->postJson("/api/v1/projects/{$project->id}/archive")->assertForbidden();

        $this->assertSame('Original', $project->fresh()->name);
        $this->assertNull($project->fresh()->archived_at);
    }

    public function test_owner_can_view_and_update_project(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->for($user, 'owner')->create(['description' => 'Old']);

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $project->id);

        $this->patchJson("/api/v1/projects/{$project->id}", ['description' => 'New', 'repository_url' => null])
            ->assertOk()
            ->assertJsonPath('data.description', 'New')
            ->assertJsonPath('data.repository_url', null)
            ->assertJsonPath('data.name', $project->name);
    }

    public function test_update_cannot_blank_the_name(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->for($user, 'owner')->create();

        $this->patchJson("/api/v1/projects/{$project->id}", ['name' => ''])
            ->assertUnprocessable()
            ->assertJsonValidationErrors('name');
    }

    public function test_member_can_view_and_update_but_not_archive(): void
    {
        $member = Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->create();
        $project->members()->attach($member);

        $this->getJson("/api/v1/projects/{$project->id}")
            ->assertOk()
            ->assertJsonPath('data.is_owner', false);
        $this->patchJson("/api/v1/projects/{$project->id}", ['name' => 'Renamed'])->assertOk();
        $this->postJson("/api/v1/projects/{$project->id}/archive")->assertForbidden();
    }

    public function test_owner_can_archive_and_unarchive(): void
    {
        $user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->for($user, 'owner')->create();

        $this->postJson("/api/v1/projects/{$project->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived', true);
        $this->assertNotNull($project->fresh()->archived_at);

        $this->deleteJson("/api/v1/projects/{$project->id}/archive")
            ->assertOk()
            ->assertJsonPath('data.archived', false);
        $this->assertNull($project->fresh()->archived_at);
    }

    public function test_missing_project_returns_404(): void
    {
        Sanctum::actingAs(User::factory()->create(), ['*']);

        $this->getJson('/api/v1/projects/999999')->assertNotFound();
    }
}
