<?php

namespace App\Support;

use App\Models\Feature;
use App\Models\FeatureDiagram;
use Illuminate\Support\Collection;

/**
 * A copyable prompt that tells the project's coding agent to check a feature's diagrams against the
 * current code and update, remove or add them. Unseen can't read the code itself, so "sync" means the
 * agent does it in the repository. It holds no token: the repository is already linked.
 */
final class DiagramPrompt
{
    /**
     * @param  Collection<int, FeatureDiagram>|null  $diagrams  already loaded by the caller
     * @return array{label: string, prompt: string}
     */
    public static function build(Feature $feature, ?Collection $diagrams = null): array
    {
        $feature->loadMissing(['project', 'acceptanceCriteria']);
        $diagrams ??= $feature->diagrams()->get();

        $prompt = view('agent.diagram-prompt', [
            'feature' => $feature,
            'name' => self::oneLine($feature->name),
            'project' => self::oneLine($feature->project->name),
            'diagrams' => $diagrams,
            'url' => rtrim((string) config('app.url'), '/'),
        ])->render();

        return [
            'label' => $diagrams->isEmpty() ? 'Draw from code' : 'Sync with code',
            'prompt' => trim(preg_replace("/\n{3,}/", "\n\n", $prompt))."\n",
        ];
    }

    /** Names are user data inside a plain-text prompt: keep them on one line. */
    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace('"', "'", $value)));
    }
}
