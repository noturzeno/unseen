<?php

namespace App\Livewire;

use App\Enums\CommentRole;
use App\Models\Ticket;
use App\Support\TicketFlow;
use Illuminate\Support\Facades\Gate;
use Livewire\Component;

/**
 * Read-only ticket page: where it is in the flow, and its thread of QA, client and team comments.
 */
class TicketDetail extends Component
{
    public Ticket $ticket;

    public function mount(Ticket $ticket): void
    {
        Gate::authorize('view', $ticket->project);
    }

    public function render()
    {
        Gate::authorize('view', $this->ticket->project);

        $this->ticket->load(['project', 'feature', 'user:id,name', 'comments.user:id,name', 'comments.report:id,summary']);
        $thread = $this->ticket->comments;

        return view('livewire.ticket-detail', [
            'flow' => TicketFlow::steps($this->ticket, $thread),
            'thread' => $thread,
            'feedbackCount' => $thread->filter(fn ($c) => $c->body && in_array($c->author_role, CommentRole::feedback(), true))->count(),
            'qaRounds' => $thread->filter(fn ($c) => $c->to_status?->value === 'ready_for_qa')->count(),
        ])->title($this->ticket->label().' '.$this->ticket->title);
    }
}
