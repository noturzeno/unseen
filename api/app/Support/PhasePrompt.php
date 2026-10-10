<?php

namespace App\Support;

use App\Enums\PhaseStatus;
use App\Models\RoadmapPhase;
use App\Services\RoadmapService;

/**
 * A copyable prompt that asks the project's coding agent to take one roadmap phase forward: work
 * through its open items, unblock it, or close it out (built and verified). No token: the repository
 * is already linked.
 */
final class PhasePrompt
{
    /**
     * @return array{action: string, label: string, prompt: string}
     */
    public static function build(RoadmapPhase $phase): array
    {
        $phase->loadMissing(['project', 'items.feature:id,name,status,verification_status']);
        $progress = RoadmapService::progress($phase);

        $open = $phase->items->where('done', false)->values();
        $doneUnverified = $phase->items->filter(fn ($i) => $i->done && $i->feature
            && $i->feature->verification_status->value !== 'verified')->values();

        $action = match (true) {
            $phase->status === PhaseStatus::Blocked => 'unblock',
            $phase->status === PhaseStatus::Done || ($open->isEmpty() && $phase->items->isNotEmpty()) => 'close',
            $phase->status === PhaseStatus::InProgress => 'continue',
            default => 'start',
        };

        $label = match ($action) {
            'unblock' => 'Unblock this phase',
            'close' => 'Close out this phase',
            'continue' => 'Continue this phase',
            default => 'Start this phase',
        };

        $statusLine = implode(', ', array_filter([
            str_replace('_', ' ', $phase->status->value),
            $progress['items'] ? "{$progress['done']} of {$progress['items']} items done ({$progress['percent']}%)" : null,
            $progress['features'] ? "{$progress['verified']} of {$progress['features']} linked features verified" : null,
            $phase->target_date ? 'target date '.$phase->target_date->toDateString().($phase->target_date->isPast() && $phase->status !== PhaseStatus::Done ? ' (overdue)' : '') : null,
        ]));

        $prompt = view('agent.phase-prompt', [
            'statusLine' => $statusLine,
            'phase' => $phase,
            'name' => self::oneLine($phase->name),
            'project' => self::oneLine($phase->project->name),
            'action' => $action,
            'progress' => $progress,
            'open' => $open,
            'doneUnverified' => $doneUnverified,
            'url' => rtrim((string) config('app.url'), '/'),
        ])->render();

        return ['action' => $action, 'label' => $label, 'prompt' => trim(preg_replace("/\n{3,}/", "\n\n", $prompt))."\n"];
    }

    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace('"', "'", $value)));
    }
}
