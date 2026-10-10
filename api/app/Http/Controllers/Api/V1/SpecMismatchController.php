<?php

namespace App\Http\Controllers\Api\V1;

use App\Enums\BuiltTo;
use App\Enums\ClaimSource;
use App\Enums\MismatchDecision;
use App\Enums\MismatchStatus;
use App\Enums\WaitingOn;
use App\Http\Controllers\Controller;
use App\Models\Project;
use App\Models\SpecMismatch;
use App\Services\SpecMismatchService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;

/**
 * PRD vs design mismatches. Agents record them and mark them applied (`record`); people decide
 * them (web UI, or `write`). See SpecMismatchService for the rules.
 */
class SpecMismatchController extends Controller
{
    public function __construct(private readonly SpecMismatchService $mismatches) {}

    public function index(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('view', $project);

        $filters = $request->validate([
            'status' => [Rule::enum(MismatchStatus::class)],
            'feature_id' => ['integer'],
            'per_page' => ['integer', 'min:1', 'max:100'],
        ]);

        $items = $project->specMismatches()->with(['feature:id,name', 'decidedBy:id,name'])
            ->when($filters['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->when($filters['feature_id'] ?? null, fn ($q, $id) => $q->where('feature_id', $id))
            ->orderByRaw("case status when 'decided' then 0 when 'open' then 1 else 2 end")
            ->latest('updated_at')->latest('id')
            ->paginate($filters['per_page'] ?? 50);

        return response()->json([
            'data' => collect($items->items())->map(fn (SpecMismatch $m) => self::present($m)),
            'meta' => ['current_page' => $items->currentPage(), 'last_page' => $items->lastPage(), 'total' => $items->total(),
                'counts' => $this->mismatches->counts($project)],
        ]);
    }

    public function show(SpecMismatch $mismatch): JsonResponse
    {
        Gate::authorize('view', $mismatch->project);

        return response()->json(['data' => self::present($mismatch->load(['feature:id,name', 'decidedBy:id,name']))]);
    }

    /** Record one mismatch, or update the one with the same `key`. */
    public function store(Request $request, Project $project): JsonResponse
    {
        Gate::authorize('update', $project);
        abort_unless($project->mismatches_enabled, 409, 'PRD vs design is turned off for this project. Turn it on in the project settings to record mismatches.');

        $data = $request->validate(self::rules($project));
        [$mismatch, $new] = $this->mismatches->record($project, $data, ClaimSource::AgentReported, $request->user());

        return response()->json(['data' => self::present($mismatch->fresh(['feature:id,name', 'decidedBy:id,name']))], $new ? 201 : 200);
    }

    public function update(Request $request, SpecMismatch $mismatch): JsonResponse
    {
        Gate::authorize('update', $mismatch->project);

        $rules = collect(self::rules($mismatch->project))->except('key')
            ->map(fn ($r, $field) => in_array($field, ['title', 'prd_says', 'design_says'], true) ? ['sometimes', ...$r] : $r)->all();
        $mismatch->update(collect($request->validate($rules))->only(SpecMismatchService::DETAILS)->all());

        return response()->json(['data' => self::present($mismatch->fresh(['feature:id,name', 'decidedBy:id,name']))]);
    }

    /** People only: the route needs the `write` ability, which agent tokens don't have. */
    public function decide(Request $request, SpecMismatch $mismatch): JsonResponse
    {
        Gate::authorize('update', $mismatch->project);

        $data = $request->validate([
            'decision' => ['required', Rule::enum(MismatchDecision::class)],
            'decision_note' => ['nullable', 'string', 'max:5000'],
        ]);
        $this->mismatches->decide($mismatch, MismatchDecision::from($data['decision']), $data['decision_note'] ?? null, $request->user());

        return response()->json(['data' => self::present($mismatch->fresh(['feature:id,name', 'decidedBy:id,name']))]);
    }

    public function applied(Request $request, SpecMismatch $mismatch): JsonResponse
    {
        Gate::authorize('update', $mismatch->project);

        $data = $request->validate(['note' => ['nullable', 'string', 'max:5000']]);
        $this->mismatches->applied($mismatch, $data['note'] ?? null);

        return response()->json(['data' => self::present($mismatch->fresh(['feature:id,name', 'decidedBy:id,name']))]);
    }

    private static function rules(Project $project): array
    {
        return [
            'key' => ['nullable', 'string', 'max:100'],
            'reference' => ['nullable', 'string', 'max:255'],
            'title' => ['required', 'string', 'max:255'],
            'prd_says' => ['required', 'string', 'max:5000'],
            'design_says' => ['required', 'string', 'max:5000'],
            'built_to' => ['nullable', Rule::enum(BuiltTo::class)],
            'built_note' => ['nullable', 'string', 'max:5000'],
            'impact' => ['nullable', 'string', 'max:5000'],
            'decide_by' => ['nullable', Rule::in([WaitingOn::Team->value, WaitingOn::Client->value])],
            'feature_id' => ['nullable', 'integer', Rule::exists('features', 'id')->where('project_id', $project->id)],
        ];
    }

    public static function present(SpecMismatch $m): array
    {
        return [
            'id' => $m->id,
            'key' => $m->key,
            'reference' => $m->reference,
            'title' => $m->title,
            'feature' => $m->feature ? ['id' => $m->feature->id, 'name' => $m->feature->name] : null,
            'prd_says' => $m->prd_says,
            'design_says' => $m->design_says,
            'built_to' => $m->built_to,
            'built_note' => $m->built_note,
            'impact' => $m->impact,
            'decide_by' => $m->decide_by,
            'status' => $m->status,
            'decision' => $m->decision,
            'decision_note' => $m->decision_note,
            'next_action' => $m->decision?->action(),
            'decided_by' => $m->relationLoaded('decidedBy') && $m->decidedBy ? ['id' => $m->decidedBy->id, 'name' => $m->decidedBy->name] : null,
            'decided_at' => $m->decided_at,
            'applied_note' => $m->applied_note,
            'applied_at' => $m->applied_at,
            'source' => $m->source,
            'created_at' => $m->created_at,
            'updated_at' => $m->updated_at,
        ];
    }
}
