<?php

namespace App\Livewire;

use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Project;
use App\Services\ProjectDashboard;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only ticket list with the flow at a glance. Coding agents record tickets and the
 * QA / client comments they are given through the API (`unseen ticket`).
 */
class TicketsIndex extends Component
{
    use WithPagination;

    public Project $project;

    /** A group (open, finished, all) or a single TicketStatus value. Groups win: "open" means every open status. */
    #[Url]
    public string $state = 'open';

    #[Url]
    public string $type = '';

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
    }

    public function updating($property): void
    {
        if (in_array($property, ['state', 'type'], true)) {
            $this->resetPage();
        }
    }

    public function render(ProjectDashboard $dashboard)
    {
        Gate::authorize('view', $this->project);

        // Unknown filter values from the URL are ignored rather than trusted.
        $group = in_array($this->state, ['open', 'finished', 'all'], true);
        $status = $group ? null : TicketStatus::tryFrom($this->state);
        $state = $group || $status ? $this->state : 'open';
        $type = TicketType::tryFrom($this->type);

        $tickets = $this->project->tickets()
            ->with(['feature:id,name', 'latestFeedback'])
            ->withCount('comments')
            ->when($status, fn ($q) => $q->where('status', $status))
            ->when($state === 'open', fn ($q) => $q->open())
            ->when($state === 'finished', fn ($q) => $q->whereNotIn('status', TicketStatus::open()))
            ->when($type, fn ($q) => $q->where('type', $type))
            ->orderByRaw("case when status in ('qa_failed', 'changes_requested') then 0 else 1 end")
            ->latest('updated_at')->latest('id')
            ->paginate(30);

        return view('livewire.tickets-index', [
            'tickets' => $tickets,
            'counts' => $dashboard->ticketCounts($this->project),
            'current' => $state,
            'feedback' => $dashboard->recentFeedback($this->project, 6),
        ])->title('Tickets · '.$this->project->name);
    }
}
