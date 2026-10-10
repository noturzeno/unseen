<?php

namespace Tests\Feature\Api\V1;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectMemberApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_owner_adds_and_removes_members_by_email(): void
    {
        $owner = Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->for($owner, 'owner')->create();
        $colleague = User::factory()->create(['email' => 'colleague@example.com']);

        $this->postJson("/api/v1/projects/{$project->id}/members", ['email' => 'Colleague@Example.com'])
            ->assertCreated()->assertJsonPath('data.id', $colleague->id);
        $this->getJson("/api/v1/projects/{$project->id}/members")
            ->assertOk()
            ->assertJsonPath('data.owner.id', $owner->id)
            ->assertJsonPath('data.members.0.id', $colleague->id);
        $this->assertTrue($project->hasMember($colleague));

        $this->deleteJson("/api/v1/projects/{$project->id}/members/{$colleague->id}")->assertNoContent();
        $this->assertFalse($project->hasMember($colleague));
    }

    public function test_add_member_validation(): void
    {
        $owner = Sanctum::actingAs(User::factory()->create(), ['*']);
        $project = Project::factory()->for($owner, 'owner')->create();
        $member = User::factory()->create();
        $project->members()->attach($member);

        foreach ([$owner->email, $member->email] as $email) {
            $this->postJson("/api/v1/projects/{$project->id}/members", ['email' => $email])
                ->assertUnprocessable()->assertJsonValidationErrors('email');
        }
        $this->postJson("/api/v1/projects/{$project->id}/members", ['email' => 'not-an-email'])->assertUnprocessable();
    }

    public function test_members_cannot_manage_members_but_can_leave(): void
    {
        $member = Sanctum::actingAs(User::factory()->create(), ['*']);
        $other = User::factory()->create();
        $project = Project::factory()->create();
        $project->members()->attach([$member->id, $other->id]);

        $this->getJson("/api/v1/projects/{$project->id}/members")->assertOk();
        $this->postJson("/api/v1/projects/{$project->id}/members", ['email' => User::factory()->create()->email])->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$project->id}/members/{$other->id}")->assertForbidden();

        $this->deleteJson("/api/v1/projects/{$project->id}/members/{$member->id}")->assertNoContent();
        $this->assertFalse($project->hasMember($member));
        $this->assertTrue($project->hasMember($other));
    }

    public function test_outsiders_and_agent_tokens_cannot_manage_members(): void
    {
        $project = Project::factory()->create();
        $outsider = User::factory()->create();

        Sanctum::actingAs($outsider, ['*']);
        $this->getJson("/api/v1/projects/{$project->id}/members")->assertForbidden();
        $this->deleteJson("/api/v1/projects/{$project->id}/members/{$outsider->id}")->assertNotFound();

        Sanctum::actingAs($project->owner, ['read', 'report']);
        $this->postJson("/api/v1/projects/{$project->id}/members", ['email' => $outsider->email])->assertForbidden();
    }
}
