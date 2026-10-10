<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\ClaimSource;
use App\Enums\CommentRole;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Services\TicketService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * Tickets and their QA / client / team thread. Coding agents record them (`record` or `write`);
 * the web UI shows them.
 */
class TicketController extends Controller
{
    public function __construct(private readonly TicketService $tickets) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $filters = $request->validate([
            'state' => [Rule::in(['open', 'finished', 'all'])],
            'status' => [Rule::enum(TicketStatus::class)],
            'type' => [Rule::enum(TicketType::class)],
            'feature_id' => ['integer'],
            'reference' => ['string', 'max:64'],
            'per_page' => ['integer', 'min:1', 'max:100'],
        ]);

        $tickets = $project->tickets()
            ->with(['feature:id,name', 'latestFeedback'])
            ->withCount('comments')
            ->when(($filters['state'] ?? 'all') === 'open', fn ($q) => $q->open())
            ->when(($filters['state'] ?? 'all') === 'finished', fn ($q) => $q->whereNotIn('status', TicketStatus::open()))
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['type'] ?? null, fn ($q, $t) => $q->where('type', $t))
            ->when($filters['feature_id'] ?? null, fn ($q, $id) => $q->where('feature_id', $id))
            ->when($filters['reference'] ?? null, fn ($q, $ref) => $q->whereRaw('lower(reference) = ?', [mb_strtolower($ref)]))
            ->latest('updated_at')->latest('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => collect($tickets->items())->map(fn (Ticket $t) => self::present($t)),
            'meta' => ['current_page' => $tickets->currentPage(), 'last_page' => $tickets->lastPage(), 'total' => $tickets->total()],
        ]);
    }

    public function show(Ticket $ticket): JsonResponse
    {
        Gate::authorize('view', $ticket->project);

        $ticket->load(['feature:id,name', 'comments.user:id,name', 'latestFeedback'])->loadCount('comments');

        return response()->json(['data' => self::present($ticket, withComments: true)]);
    }

    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);

        $data = $request->validate([
            ...self::detailRules($project),
            'title' => ['required', 'string', 'max:255'],
            'reference' => ['nullable', 'string', 'max:64', Rule::unique('tickets')->where('project_id', $project->id)],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
            'comment' => ['nullable', 'string', 'max:5000'],
            'author_name' => ['nullable', 'string', 'max:100'],
        ]);

        $ticket = $this->tickets->create($project, $data, ClaimSource::AgentReported, $request->user());

        return response()->json(['data' => self::present($ticket->load(['feature:id,name', 'comments.user:id,name'])->loadCount('comments'), withComments: true)], 201);
    }

    public function update(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('update', $ticket->project);

        $data = $request->validate([
            ...self::detailRules($ticket->project),
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'reference' => ['sometimes', 'nullable', 'string', 'max:64', Rule::unique('tickets')->where('project_id', $ticket->project_id)->ignore($ticket->id)],
            'status' => ['sometimes', 'required', Rule::enum(TicketStatus::class)],
            'note' => ['nullable', 'string', 'max:5000'],
            'author_role' => ['nullable', Rule::enum(CommentRole::class)],
            'author_name' => ['nullable', 'string', 'max:100'],
        ]);

        $this->tickets->update($ticket, $data, ClaimSource::AgentReported, $request->user());

        return response()->json(['data' => self::present($ticket->fresh(['feature:id,name', 'comments.user:id,name'])->loadCount('comments'), withComments: true)]);
    }

    public function comment(Request $request, Ticket $ticket): JsonResponse
    {
        Gate::authorize('update', $ticket->project);

        $data = $request->validate([
            'author_role' => ['required', Rule::enum(CommentRole::class)],
            'author_name' => ['nullable', 'string', 'max:100'],
            'body' => ['required', 'string', 'max:5000'],
            'status' => ['nullable', Rule::enum(TicketStatus::class)],
        ]);

        $comment = $this->tickets->comment($ticket, $data, ClaimSource::AgentReported, $request->user());

        return response()->json(['data' => [
            'comment' => self::presentComment($comment->load('user:id,name')),
            'ticket' => self::present($ticket->fresh('feature:id,name')->loadCount('comments')),
        ]], 201);
    }

    /** Fields shared by create and update. */
    private static function detailRules(Project $project): array
    {
        return [
            'description' => ['nullable', 'string', 'max:10000'],
            'type' => ['nullable', Rule::enum(TicketType::class)],
            'priority' => ['nullable', Rule::in(Ticket::PRIORITIES)],
            'raised_by' => ['nullable', Rule::enum(CommentRole::class)],
            'feature_id' => ['nullable', 'integer', Rule::exists('features', 'id')->where('project_id', $project->id)],
        ];
    }

    public static function present(Ticket $ticket, bool $withComments = false): array
    {
        $feedback = $ticket->relationLoaded('latestFeedback') ? $ticket->latestFeedback : null;

        return [
            'id' => $ticket->id,
            'reference' => $ticket->reference,
            'title' => $ticket->title,
            'description' => $ticket->description,
            'type' => $ticket->type,
            'status' => $ticket->status,
            'priority' => $ticket->priority,
            'raised_by' => $ticket->raised_by,
            'feature' => $ticket->feature ? ['id' => $ticket->feature->id, 'name' => $ticket->feature->name] : null,
            'source' => $ticket->source,
            'comments_count' => $ticket->comments_count,
            'latest_feedback' => $feedback ? self::presentComment($feedback) : null,
            'closed_at' => $ticket->closed_at,
            'created_at' => $ticket->created_at,
            'updated_at' => $ticket->updated_at,
            ...($withComments ? ['comments' => $ticket->comments->map(fn (TicketComment $c) => self::presentComment($c))->all()] : []),
        ];
    }

    public static function presentComment(TicketComment $comment): array
    {
        return [
            'id' => $comment->id,
            'ticket_id' => $comment->ticket_id,
            'author_role' => $comment->author_role,
            'author_name' => $comment->author_name,
            'body' => $comment->body,
            'from_status' => $comment->from_status,
            'to_status' => $comment->to_status,
            'report_id' => $comment->development_report_id,
            'source' => $comment->source,
            'recorded_by' => $comment->relationLoaded('user') && $comment->user ? ['id' => $comment->user->id, 'name' => $comment->user->name] : null,
            'created_at' => $comment->created_at,
        ];
    }
}
