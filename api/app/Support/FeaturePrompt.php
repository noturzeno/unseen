<?php

namespace App\Support;

use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\RoadmapItem;

/**
 * A copyable prompt that tells the project's coding agent to take one feature to its next step:
 * build it, unblock it, get it verified, or fix failing checks. It holds no token: the repository is
 * already linked, and the agent uses the CLI's stored token.
 */
final class FeaturePrompt
{
    /**
     * @return array{action: string, label: string, prompt: string}
     */
    public static function build(Feature $feature): array
    {
        $feature->loadMissing(['project', 'category', 'acceptanceCriteria', 'checks.latestResult']);

        $action = match (true) {
            $feature->status === FeatureStatus::Ignored => 'ignored',
            $feature->verification_status === VerificationStatus::Failed => 'fix',
            $feature->status === FeatureStatus::Implemented && $feature->verification_status === VerificationStatus::Verified => 'extend',
            $feature->status === FeatureStatus::Implemented => 'verify',
            $feature->status === FeatureStatus::Blocked => 'unblock',
            $feature->status === FeatureStatus::NotImplemented => 'revisit',
            $feature->status === FeatureStatus::InProgress => 'continue',
            default => 'build',
        };

        $label = match ($action) {
            'fix' => 'Fix the failing checks',
            'extend' => 'Change or extend it',
            'verify' => 'Get it verified',
            'unblock' => 'Unblock it',
            'revisit' => 'Revisit it',
            'ignored' => 'Nothing to build: ignored',
            'continue' => 'Continue building it',
            default => 'Start building it',
        };

        if ($action === 'ignored') {
            return ['action' => $action, 'label' => $label, 'prompt' => self::ignored($feature)];
        }

        $roadmap = RoadmapItem::query()
            ->where('feature_id', $feature->id)
            ->with('phase:id,key,name')
            ->orderBy('roadmap_phase_id')->orderBy('position')
            ->get();

        $prompt = view('agent.feature-prompt', [
            'feature' => $feature,
            'name' => self::oneLine($feature->name),
            'project' => self::oneLine($feature->project->name),
            'action' => $action,
            'checks' => $feature->checks,
            'roadmap' => $roadmap,
            'url' => rtrim((string) config('app.url'), '/'),
        ])->render();

        return ['action' => $action, 'label' => $label, 'prompt' => trim(preg_replace("/\n{3,}/", "\n\n", $prompt))."\n"];
    }

    /** An ignored feature has nothing to do: the prompt only tells the agent to leave it alone. */
    private static function ignored(Feature $feature): string
    {
        $reason = $feature->statusHistory()->where('field', 'status')->where('to_value', FeatureStatus::Ignored->value)->value('note');

        return 'The Unseen feature "'.self::oneLine($feature->name).'" (feature #'.$feature->id.' in project "'.self::oneLine($feature->project->name).'") is ignored: '
            ."a person decided it won't be built.\n"
            .($reason ? 'Their reason: '.self::oneLine($reason)."\n" : '')
            ."Don't build, change or report on it. If you think it is needed after all, tell me why and stop.\n";
    }

    /** Names are user data inside a plain-text prompt: keep them on one line. */
    private static function oneLine(string $value): string
    {
        return trim(preg_replace('/\s+/', ' ', str_replace('"', "'", $value)));
    }
}
