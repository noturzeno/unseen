<?php

namespace Tests\Feature\Api\V1;

use App\Models\Project;
use App\Models\UnresolvedItem;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class UnresolvedItemApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->project = Project::factory()->for($this->user, 'owner')->create();
    }

    public function test_reports_create_open_items(): void
    {
        $this->postJson("/api/v1/projects/{$this->project->id}/reports", ['summary' => 'x', 'unresolved' => ['Add rate limiting', 'Write docs']])->assertCreated();

        $this->getJson("/api/v1/projects/{$this->project->id}/unresolved")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.status', 'open');
    }

    public function test_user_resolves_and_reopens(): void
    {
        $item = UnresolvedItem::factory()->for($this->project)->create();

        $this->postJson("/api/v1/unresolved/{$item->id}/resolve", ['note' => 'Done in PR 12'])
            ->assertOk()
            ->assertJsonPath('data.status', 'resolved')
            ->assertJsonPath('data.resolved_source', 'agent_reported')
            ->assertJsonPath('data.resolved_by.id', $this->user->id)
            ->assertJsonPath('data.resolution_note', 'Done in PR 12');
        $this->getJson("/api/v1/projects/{$this->project->id}/unresolved")->assertJsonCount(0, 'data');
        $this->getJson("/api/v1/projects/{$this->project->id}/unresolved?status=resolved")->assertJsonCount(1, 'data');

        $this->postJson("/api/v1/unresolved/{$item->id}/reopen")->assertOk()->assertJsonPath('data.status', 'open')->assertJsonPath('data.resolved_by', null);
    }

    public function test_a_report_can_close_items_as_its_own_claim(): void
    {
        $item = UnresolvedItem::factory()->for($this->project)->create();
        $foreign = UnresolvedItem::factory()->create();

        $this->postJson("/api/v1/projects/{$this->project->id}/reports", ['summary' => 'Fixed it', 'resolves' => [$foreign->id]])
            ->assertUnprocessable()->assertJsonValidationErrors('resolves.0');

        $reportId = $this->postJson("/api/v1/projects/{$this->project->id}/reports", ['summary' => 'Fixed it', 'resolves' => [$item->id]])
            ->assertCreated()->json('data.id');

        $item->refresh();
        $this->assertSame(UnresolvedItem::RESOLVED, $item->status);
        $this->assertSame('agent_reported', $item->resolved_source->value);
        $this->assertSame($reportId, $item->resolved_report_id);
        $this->assertSame(UnresolvedItem::OPEN, $foreign->fresh()->status);
    }

    public function test_access_rules(): void
    {
        $item = UnresolvedItem::factory()->create();

        $this->getJson("/api/v1/projects/{$item->project_id}/unresolved")->assertForbidden();
        $this->postJson("/api/v1/unresolved/{$item->id}/resolve")->assertForbidden();

        $mine = UnresolvedItem::factory()->for($this->project)->create();
        Sanctum::actingAs($this->user, ['read', 'report']);
        $this->postJson("/api/v1/unresolved/{$mine->id}/resolve")->assertForbidden();
    }
}
