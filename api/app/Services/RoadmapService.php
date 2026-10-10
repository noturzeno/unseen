<?php

namespace App\Services;

use App\Enums\ClaimSource;
use App\Enums\PhaseStatus;
use App\Models\Project;
use App\Models\RoadmapPhase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

/**
 * Keeps a project's roadmap identical to the plan in its repository. The coding agent sends the whole
 * roadmap; phases are matched by `key`, phases not sent are removed, and each phase's implementation
 * list is replaced. A phase without a status gets one worked out from its list.
 */
class RoadmapService
{
    public const MAX_PHASES = 50;

    public const MAX_ITEMS = 200;

    /**
     * @return array<string, list<mixed>>
     */
    public static function rules(Project $project): array
    {
        return [
            'phases' => ['present', 'array', 'max:'.self::MAX_PHASES],
            'phases.*.key' => ['required', 'string', 'max:100', 'distinct:ignore_case'],
            'phases.*.name' => ['required', 'string', 'max:255'],
            'phases.*.description' => ['nullable', 'string', 'max:5000'],
            'phases.*.status' => ['nullable', Rule::enum(PhaseStatus::class)],
            'phases.*.target_date' => ['nullable', 'date_format:Y-m-d'],
            'phases.*.items' => ['sometimes', 'array', 'max:'.self::MAX_ITEMS],
            'phases.*.items.*.text' => ['required', 'string', 'max:1000'],
            'phases.*.items.*.done' => ['sometimes', 'boolean'],
            'phases.*.items.*.feature_id' => ['nullable', 'integer', Rule::exists('features', 'id')->where('project_id', $project->id)],
        ];
    }

    /**
     * @param  list<array{key: string, name: string, description?: ?string, status?: ?string, target_date?: ?string,
     *     items?: list<array{text: string, done?: bool, feature_id?: ?int}>}>  $phases  Already validated.
     * @return Collection<int, RoadmapPhase>
     */
    public function sync(Project $project, array $phases, ClaimSource $source): Collection
    {
        DB::transaction(function () use ($project, $phases, $source) {
            $existing = $project->roadmapPhases()->get()->keyBy(fn (RoadmapPhase $p) => mb_strtolower($p->key));
            $kept = [];

            foreach (array_values($phases) as $position => $data) {
                $items = array_values($data['items'] ?? []);
                $phase = $existing[mb_strtolower($data['key'])] ?? $project->roadmapPhases()->make();
                $phase->fill([
                    'key' => $data['key'],
                    'name' => $data['name'],
                    'description' => $data['description'] ?? null,
                    'status' => isset($data['status']) ? PhaseStatus::from($data['status']) : self::derivedStatus($items),
                    'target_date' => $data['target_date'] ?? null,
                    'position' => $position,
                ]);
                $phase->source = $source;
                $phase->save();
                $kept[] = $phase->id;

                $phase->items()->delete();
                foreach ($items as $i => $item) {
                    $phase->items()->create([
                        'text' => $item['text'],
                        'done' => (bool) ($item['done'] ?? false),
                        'feature_id' => $item['feature_id'] ?? null,
                        'position' => $i,
                    ]);
                }
            }

            $project->roadmapPhases()->whereNotIn('id', $kept)->delete();
            $project->forceFill(['roadmap_synced_at' => Carbon::now()])->save();
        });

        return $this->phases($project);
    }

    /**
     * @return Collection<int, RoadmapPhase>
     */
    public function phases(Project $project): Collection
    {
        return $project->roadmapPhases()->with('items.feature:id,name,status,verification_status')->get();
    }

    /**
     * planned while nothing is done, done when everything is, in progress in between.
     *
     * @param  list<array{done?: bool}>  $items
     */
    public static function derivedStatus(array $items): PhaseStatus
    {
        $done = count(array_filter($items, fn ($i) => (bool) ($i['done'] ?? false)));

        return match (true) {
            $done === 0 => PhaseStatus::Planned,
            $done === count($items) => PhaseStatus::Done,
            default => PhaseStatus::InProgress,
        };
    }

    /**
     * Progress numbers for one phase: list items done, and linked features implemented and verified.
     *
     * @return array{items: int, done: int, percent: ?int, features: int, implemented: int, verified: int}
     */
    public static function progress(RoadmapPhase $phase): array
    {
        $items = $phase->items;
        $features = $items->pluck('feature')->filter()->unique('id');

        return [
            'items' => $items->count(),
            'done' => $items->where('done', true)->count(),
            'percent' => $items->isEmpty() ? null : intdiv($items->where('done', true)->count() * 100, $items->count()),
            'features' => $features->count(),
            'implemented' => $features->filter(fn ($f) => $f->status->value === 'implemented')->count(),
            'verified' => $features->filter(fn ($f) => $f->status->value === 'implemented' && $f->verification_status->value === 'verified')->count(),
        ];
    }
}
