<?php

namespace App\Support;

use App\Enums\MismatchStatus;
use App\Models\Project;

/**
 * Prompts for the project's coding agent about PRD vs design mismatches: find and record them,
 * or carry out the decisions people made. No token: the repository is already linked.
 */
final class MismatchPrompt
{
    public const MAX_ITEMS = 30;

    public static function find(Project $project): string
    {
        return self::render('agent.mismatch-find-prompt', $project, []);
    }

    /** Null when nothing is decided and waiting to be applied. */
    public static function apply(Project $project): ?string
    {
        $items = $project->specMismatches()->with('feature:id,name')->where('status', MismatchStatus::Decided)
            ->oldest('decided_at')->limit(self::MAX_ITEMS)->get();

        return $items->isEmpty() ? null : self::render('agent.mismatch-apply-prompt', $project, ['items' => $items]);
    }

    private static function render(string $view, Project $project, array $data): string
    {
        $text = view($view, $data + [
            'project' => trim(preg_replace('/\s+/', ' ', str_replace('"', "'", $project->name))),
            'projectId' => $project->id,
            'url' => rtrim((string) config('app.url'), '/'),
        ])->render();

        return trim(preg_replace("/\n{3,}/", "\n\n", $text))."\n";
    }
}
