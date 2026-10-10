<?php

namespace Tests\Feature\Api\V1;

use App\Models\Feature;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class TicketApiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = Sanctum::actingAs(User::factory()->create(), ['read', 'report', 'record']);
        $this->project = Project::factory()->for($this->user, 'owner')->create();
    }

    public function test_agent_records_a_ticket_with_its_opening_comment(): void
    {
        $feature = Feature::factory()->for($this->project)->create();

        $this->postJson("/api/v1/projects/{$this->project->id}/tickets", [
            'title' => 'Checkout button does nothing on Safari',
            'reference' => 'WEB-142',
            'type' => 'bug',
            'priority' => 'high',
            'raised_by' => 'client',
            'feature_id' => $feature->id,
            'comment' => 'Clicking Pay on iPhone does nothing.',
        ])->assertCreated()
            ->assertJsonPath('data.reference', 'WEB-142')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.feature.id', $feature->id)
            ->assertJsonPath('data.source', 'agent_reported')
            ->assertJsonPath('data.comments.0.author_role', 'client')
            ->assertJsonPath('data.comments.0.to_status', 'open')
            ->assertJsonPath('data.comments.0.body', 'Clicking Pay on iPhone does nothing.');

        // References are unique per project.
        $this->postJson("/api/v1/projects/{$this->project->id}/tickets", ['title' => 'Again', 'reference' => 'WEB-142'])
            ->assertUnprocessable()->assertJsonValidationErrors('reference');
    }

    public function test_a_minimal_ticket_gets_defaults(): void
    {
        $this->postJson("/api/v1/projects/{$this->project->id}/tickets", ['title' => 'Tidy footer'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'task')
            ->assertJsonPath('data.status', 'open')
            ->assertJsonPath('data.comments.0.author_role', 'agent')
            ->assertJsonPath('data.comments.0.body', null);
    }

    public function test_feature_must_belong_to_the_project(): void
    {
        $foreign = Feature::factory()->create();

        $this->postJson("/api/v1/projects/{$this->project->id}/tickets", ['title' => 'x', 'feature_id' => $foreign->id])
            ->assertUnprocessable()->assertJsonValidationErrors('feature_id');
    }

    public function test_the_flow_through_qa_and_client_review_is_recorded(): void
    {
        $ticket = Ticket::factory()->for($this->project)->create();

        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['status' => 'ready_for_qa', 'note' => 'Fixed the click handler'])
            ->assertOk()->assertJsonPath('data.status', 'ready_for_qa');

        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", [
            'author_role' => 'qa', 'body' => 'Still broken on iOS 17.', 'status' => 'qa_failed',
        ])->assertCreated()
            ->assertJsonPath('data.comment.from_status', 'ready_for_qa')
            ->assertJsonPath('data.comment.to_status', 'qa_failed')
            ->assertJsonPath('data.ticket.status', 'qa_failed');

        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['status' => 'client_review'])->assertOk();
        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", ['author_role' => 'client', 'body' => 'Looks good, approved.', 'status' => 'done'])
            ->assertCreated()->assertJsonPath('data.ticket.status', 'done');

        $ticket->refresh();
        $this->assertNotNull($ticket->closed_at);

        $comments = $this->getJson("/api/v1/tickets/{$ticket->id}")->assertOk()->json('data.comments');
        $this->assertSame(['agent', 'qa', 'agent', 'client'], array_column($comments, 'author_role'));
        $this->assertSame(['ready_for_qa', 'qa_failed', 'client_review', 'done'], array_column($comments, 'to_status'));

        // Reopening clears closed_at.
        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", ['author_role' => 'client', 'body' => 'Broken again', 'status' => 'changes_requested'])->assertCreated();
        $this->assertNull($ticket->fresh()->closed_at);
    }

    public function test_a_plain_comment_does_not_change_status(): void
    {
        $ticket = Ticket::factory()->for($this->project)->create(['status' => 'in_progress']);

        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", ['author_role' => 'qa', 'body' => 'Please also test Firefox.'])
            ->assertCreated()->assertJsonPath('data.comment.to_status', null)->assertJsonPath('data.ticket.status', 'in_progress');

        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", ['author_role' => 'boss', 'body' => 'x'])
            ->assertUnprocessable()->assertJsonValidationErrors('author_role');
        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", ['author_role' => 'qa'])
            ->assertUnprocessable()->assertJsonValidationErrors('body');
    }

    public function test_list_filters(): void
    {
        Ticket::factory()->for($this->project)->create(['reference' => 'WEB-1', 'status' => 'open']);
        Ticket::factory()->for($this->project)->create(['reference' => 'WEB-2', 'status' => 'done']);
        Ticket::factory()->create(['reference' => 'WEB-3']);

        $this->getJson("/api/v1/projects/{$this->project->id}/tickets")->assertOk()->assertJsonCount(2, 'data');
        $this->getJson("/api/v1/projects/{$this->project->id}/tickets?state=open")->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'WEB-1');
        $this->getJson("/api/v1/projects/{$this->project->id}/tickets?state=finished")->assertJsonCount(1, 'data')->assertJsonPath('data.0.reference', 'WEB-2');
        $this->getJson("/api/v1/projects/{$this->project->id}/tickets?reference=web-2")->assertJsonCount(1, 'data');
    }

    public function test_a_report_moves_tickets_and_links_itself(): void
    {
        $ticket = Ticket::factory()->for($this->project)->create(['status' => 'qa_failed']);
        $foreign = Ticket::factory()->create();

        $this->postJson("/api/v1/projects/{$this->project->id}/reports", ['summary' => 'x', 'tickets' => [['id' => $foreign->id]]])
            ->assertUnprocessable()->assertJsonValidationErrors('tickets.0.id');

        $report = $this->postJson("/api/v1/projects/{$this->project->id}/reports", [
            'summary' => 'Fixed Safari click',
            'tickets' => [['id' => $ticket->id, 'status' => 'ready_for_qa', 'note' => 'Handler bound on touchend too', 'extra' => 'dropped']],
        ])->assertCreated()
            ->assertJsonPath('data.tickets.0.id', $ticket->id)
            ->assertJsonPath('data.tickets.0.to_status', 'ready_for_qa')
            ->json('data');

        $this->assertSame('ready_for_qa', $ticket->fresh()->status->value);
        $this->getJson("/api/v1/tickets/{$ticket->id}")->assertJsonPath('data.comments.0.report_id', $report['id']);
    }

    public function test_dashboard_shows_open_tickets_and_qa_and_client_feedback(): void
    {
        $sentBack = Ticket::factory()->for($this->project)->create(['status' => 'in_progress']);
        Ticket::factory()->for($this->project)->create(['status' => 'done']);

        $this->postJson("/api/v1/tickets/{$sentBack->id}/comments", ['author_role' => 'developer', 'body' => 'internal'])->assertCreated();
        $this->postJson("/api/v1/tickets/{$sentBack->id}/comments", ['author_role' => 'qa', 'body' => 'Fails on mobile', 'status' => 'qa_failed'])->assertCreated();

        $this->getJson("/api/v1/projects/{$this->project->id}/dashboard")->assertOk()
            ->assertJsonPath('data.summary.tickets.total', 2)
            ->assertJsonPath('data.summary.tickets.open', 1)
            ->assertJsonPath('data.summary.tickets.sent_back', 1)
            ->assertJsonPath('data.summary.tickets.by_status.done', 1)
            ->assertJsonCount(1, 'data.open_tickets')
            ->assertJsonPath('data.open_tickets.0.latest_feedback.body', 'Fails on mobile')
            ->assertJsonCount(1, 'data.recent_feedback')
            ->assertJsonPath('data.recent_feedback.0.author_role', 'qa')
            ->assertJsonPath('data.recent_feedback.0.ticket.id', $sentBack->id);
    }

    public function test_read_only_and_foreign_tokens_cannot_write(): void
    {
        $ticket = Ticket::factory()->for($this->project)->create();

        Sanctum::actingAs($this->user, ['read', 'report']);
        $this->postJson("/api/v1/tickets/{$ticket->id}/comments", ['author_role' => 'qa', 'body' => 'x'])->assertForbidden();
        $this->postJson("/api/v1/projects/{$this->project->id}/tickets", ['title' => 'x'])->assertForbidden();

        Sanctum::actingAs(User::factory()->create(), ['*']);
        $this->getJson("/api/v1/tickets/{$ticket->id}")->assertForbidden();
        $this->patchJson("/api/v1/tickets/{$ticket->id}", ['status' => 'done'])->assertForbidden();
        $this->getJson("/api/v1/projects/{$this->project->id}/tickets")->assertForbidden();
    }
}
