<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClaimSource;
use App\Enums\WaitingOn;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\UnresolvedItem;
use App\Services\UnresolvedItemService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

class UnresolvedItemController extends Controller
{
    public function __construct(private readonly UnresolvedItemService $items) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $filters = $request->validate([
            'status' => [Rule::in([UnresolvedItem::OPEN, UnresolvedItem::RESOLVED])],
            'waiting_on' => [Rule::in([...array_column(WaitingOn::cases(), 'value'), 'unsorted'])],
            'per_page' => ['integer', 'min:1', 'max:100'],
        ]);

        $items = $project->unresolvedItems()
            ->with('resolvedBy:id,name')
            ->where('status', $filters['status'] ?? UnresolvedItem::OPEN)
            ->when($filters['waiting_on'] ?? null, fn ($q, $w) => $w === 'unsorted' ? $q->whereNull('waiting_on') : $q->where('waiting_on', $w))
            ->latest()->latest('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => collect($items->items())->map(fn (UnresolvedItem $i) => self::present($i)),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total()],
        ]);
    }

    public function resolve(Request $request, UnresolvedItem $item): JsonResponse
    {
        Gate::authorize('update', $item->project);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:2000']]);
        $this->items->resolve($item, ClaimSource::AgentReported, $request->user(), $data['note'] ?? null);

        return response()->json(['data' => self::present($item->fresh('resolvedBy'))]);
    }

    /**
     * Sort an item: who it waits on (client, team, agent, or null for not sorted) and what is needed.
     */
    public function update(Request $request, UnresolvedItem $item): JsonResponse
    {
        Gate::authorize('update', $item->project);

        $data = $request->validate([
            'waiting_on' => ['present', 'nullable', Rule::enum(WaitingOn::class)],
            'decision_note' => ['nullable', 'string', 'max:2000'],
        ]);
        $item->update(['waiting_on' => $data['waiting_on'], 'decision_note' => $data['decision_note'] ?? null]);

        return response()->json(['data' => self::present($item->fresh('resolvedBy'))]);
    }

    public function reopen(UnresolvedItem $item): JsonResponse
    {
        Gate::authorize('update', $item->project);

        $this->items->reopen($item);

        return response()->json(['data' => self::present($item->fresh())]);
    }

    public static function present(UnresolvedItem $item): array
    {
        return [
            'id' => $item->id,
            'text' => $item->text,
            'status' => $item->status,
            'waiting_on' => $item->waiting_on,
            'decision_note' => $item->decision_note,
            // A person's answer, given on the Unresolved page: carry it out.
            'decision' => $item->decision,
            'decided_at' => $item->decided_at,
            'report_id' => $item->development_report_id,
            'resolved_at' => $item->resolved_at,
            'resolved_by' => $item->relationLoaded('resolvedBy') && $item->resolvedBy ? ['id' => $item->resolvedBy->id, 'name' => $item->resolvedBy->name] : null,
            'resolved_source' => $item->resolved_source,
            'resolved_report_id' => $item->resolved_report_id,
            'resolution_note' => $item->resolution_note,
            'created_at' => $item->created_at,
        ];
    }
}
