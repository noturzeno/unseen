<?php

namespace App\Livewire;

use App\Enums\WaitingOn;
use App\Models\Project;
use App\Models\UnresolvedItem;
use App\Services\ProjectDashboard;
use App\Services\UnresolvedItemService;
use App\Support\UnresolvedPrompt;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Open and closed unresolved work. Agents close items through reports (`resolves`) or the API.
 * People answer items that wait on them or the client here; the item's prompt then carries the decision.
 */
class UnresolvedIndex extends Component
{
    use WithPagination;

    public Project $project;

    #[Url]
    public string $status = UnresolvedItem::OPEN;

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
    }

    /** Who open items wait on: team, client, agent, unsorted, or "" for everyone. */
    #[Url]
    public string $waiting = '';

    /** Decisions being typed, by item id. */
    public array $decisions = [];

    public function decide(int $id, UnresolvedItemService $items): void
    {
        Gate::authorize('update', $this->project);
        $item = $this->project->unresolvedItems()->where('status', UnresolvedItem::OPEN)->findOrFail($id);
        $this->validate(["decisions.{$id}" => ['required', 'string', 'max:2000']], [], ["decisions.{$id}" => 'decision']);

        $items->decide($item, trim($this->decisions[$id]), auth()->user());
        unset($this->decisions[$id]);
        $this->dispatch('decided', id: $id);
    }

    public function updatedStatus(): void
    {
        $this->resetPage();
    }

    public function updatedWaiting(): void
    {
        $this->resetPage();
    }

    public function render(ProjectDashboard $dashboard)
    {
        Gate::authorize('view', $this->project);
        $status = in_array($this->status, [UnresolvedItem::OPEN, UnresolvedItem::RESOLVED], true) ? $this->status : UnresolvedItem::OPEN;

        // Unknown filter values from the URL are ignored rather than trusted.
        $waiting = WaitingOn::tryFrom($this->waiting);
        $unsorted = $this->waiting === 'unsorted';

        // Open items are grouped: waiting on you, on the client, not sorted, then what the agent can do.
        $items = $this->project->unresolvedItems()->with(['report:id,summary', 'resolvedBy:id,name', 'decidedBy:id,name'])
            ->where('status', $status)
            ->when($status === UnresolvedItem::OPEN && $waiting, fn ($q) => $q->where('waiting_on', $waiting))
            ->when($status === UnresolvedItem::OPEN && $unsorted, fn ($q) => $q->whereNull('waiting_on'))
            ->when($status === UnresolvedItem::OPEN, fn ($q) => $q->orderByRaw("case waiting_on when 'team' then 0 when 'client' then 1 when 'agent' then 3 else 2 end"))
            ->when($status === UnresolvedItem::RESOLVED, fn ($q) => $q->latest('resolved_at'), fn ($q) => $q->latest())
            ->latest('id')->paginate(30);
        $items->each(fn (UnresolvedItem $item) => $item->setRelation('project', $this->project));

        // Prompts for the project's coding agent (no token in them; the repo is already linked).
        $allOpen = $status === UnresolvedItem::OPEN
            ? $this->project->unresolvedItems()->where('status', UnresolvedItem::OPEN)->latest()->latest('id')
                ->limit(UnresolvedPrompt::MAX_ITEMS + 1)->get()
            : collect();

        $counts = $this->project->unresolvedItems()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');

        $openItems = fn () => $this->project->unresolvedItems()->where('status', UnresolvedItem::OPEN)->latest()->latest('id');
        $waitingCounts = $dashboard->waitingOnCounts($this->project);

        return view('livewire.unresolved-index', [
            'items' => $items,
            'waitingCounts' => $waitingCounts,
            'waitingFilter' => $waiting ? $waiting->value : ($unsorted ? 'unsorted' : ''),
            'sortPrompt' => $status === UnresolvedItem::OPEN && $waitingCounts['unsorted'] > 0
                ? UnresolvedPrompt::forSorting($this->project, $openItems()->limit(UnresolvedPrompt::MAX_ITEMS + 1)->get(), (int) ($counts[UnresolvedItem::OPEN] ?? 0))
                : null,
            'clientQuestions' => $items->getCollection()->filter(fn (UnresolvedItem $i) => $i->isOpen() && $i->waiting_on === WaitingOn::Client)
                ->mapWithKeys(fn (UnresolvedItem $i) => [$i->id => UnresolvedPrompt::clientQuestion($i)]),
            'prompts' => $status === UnresolvedItem::OPEN
                ? $items->getCollection()->mapWithKeys(fn (UnresolvedItem $item) => [$item->id => UnresolvedPrompt::forItem($item)])
                : collect(),
            'allPrompt' => $allOpen->isNotEmpty() ? UnresolvedPrompt::forAll($this->project, $allOpen, (int) ($counts[UnresolvedItem::OPEN] ?? 0)) : null,
            'current' => $status,
            'counts' => $counts,
        ])->title('Unresolved work · '.$this->project->name);
    }
}
