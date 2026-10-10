<?php

namespace Tests\Feature\Web;

use App\Enums\ClaimSource;
use App\Models\Feature;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\User;
use App\Services\TicketService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

class TicketUiTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    private Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create();
        $this->project = Project::factory()->for($this->user, 'owner')->create();
        $this->actingAs($this->user);
    }

    private function sentBackTicket(): Ticket
    {
        $feature = Feature::factory()->for($this->project)->create(['name' => 'Checkout']);
        $service = app(TicketService::class);
        $ticket = $service->create($this->project, [
            'title' => 'Pay button dead on Safari', 'reference' => 'WEB-142', 'type' => 'bug',
            'raised_by' => 'client', 'feature_id' => $feature->id, 'comment' => 'Nothing happens when I tap Pay.',
        ], ClaimSource::AgentReported, $this->user);
        $service->update($ticket, ['status' => 'ready_for_qa', 'note' => 'Bound touchend'], ClaimSource::AgentReported, $this->user);
        $service->comment($ticket, ['author_role' => 'qa', 'body' => 'Still fails on iOS 17', 'status' => 'qa_failed'], ClaimSource::AgentReported, $this->user);

        return $ticket;
    }

    public function test_ticket_pages_and_dashboard_show_the_flow_and_feedback(): void
    {
        $ticket = $this->sentBackTicket();

        $this->get("/projects/{$this->project->id}/tickets")->assertOk()
            ->assertSee('WEB-142')->assertSee('Pay button dead on Safari')->assertSee('QA failed')
            ->assertSee('Still fails on iOS 17')->assertSee('QA &amp; client feedback', false);

        $this->get("/tickets/{$ticket->id}")->assertOk()
            ->assertSee('Ticket flow', false)->assertSee('Client review')
            ->assertSee('Nothing happens when I tap Pay.')->assertSee('Bound touchend')->assertSee('Still fails on iOS 17')
            ->assertSeeInOrder(['Client', 'Coding agent', 'QA']);

        $this->get("/projects/{$this->project->id}")->assertOk()
            ->assertSee('1 sent back')->assertSee('Still fails on iOS 17')->assertSee('Nothing happens when I tap Pay.');

        $this->get("/features/{$ticket->feature_id}")->assertOk()->assertSee('WEB-142');
    }

    public function test_filters_and_closed_tickets(): void
    {
        Ticket::factory()->for($this->project)->create(['title' => 'Shipped thing', 'status' => 'done']);
        Ticket::factory()->for($this->project)->create(['title' => 'Open thing']);
        Ticket::factory()->for($this->project)->create(['title' => 'With QA thing', 'status' => 'ready_for_qa']);
        Ticket::factory()->for($this->project)->create(['title' => 'Dropped thing', 'status' => 'closed']);

        Livewire::test('tickets-index', ['project' => $this->project])
            ->assertSee('Open thing')->assertSee('With QA thing')->assertDontSee('Shipped thing')->assertDontSee('Dropped thing')
            ->set('state', 'finished')->assertSee('Shipped thing')->assertSee('Dropped thing')->assertDontSee('Open thing')
            ->set('state', 'closed')->assertSee('Dropped thing')->assertDontSee('Shipped thing')
            ->set('state', 'ready_for_qa')->assertSee('With QA thing')->assertDontSee('Open thing')
            ->set('state', 'nonsense')->assertSee('Open thing')->assertSee('With QA thing');
    }

    public function test_outsiders_cannot_see_tickets(): void
    {
        $ticket = Ticket::factory()->create();

        $this->get("/tickets/{$ticket->id}")->assertForbidden();
        $this->get("/projects/{$ticket->project_id}/tickets")->assertForbidden();
    }
}
