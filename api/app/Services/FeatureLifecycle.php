<?php

namespace App\Services;

use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\FeatureStatusChange;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where a feature is on the path Planned → In progress → Implemented → Verified, for the
 * feature page's lifecycle tracker. Implementation and verification stay separate facts:
 * the last step is only "done" when the feature is implemented AND verified.
 *
 * Step states: done, current, upcoming, blocked (stuck on this step), failed (verification
 * failed), waiting (implemented, awaiting verification), stopped (marked not implemented).
 */
class FeatureLifecycle
{
    /**
     * @param  Collection<int, FeatureStatusChange>  $history  newest first
     * @return list<array{key: string, label: string, state: string, at: ?Carbon, hint: ?string}>
     */
    public static function steps(Feature $feature, Collection $history): array
    {
        $reached = fn (string $field, string $value): ?Carbon => $history
            ->where('field', $field)->where('to_value', $value)->sortBy('created_at')->first()?->created_at;

        $steps = [
            // Tracking starts at creation, or at the first recorded change if that is earlier (imported history).
            ['key' => 'planned', 'label' => 'Planned', 'at' => $reached('status', 'planned')
                ?? collect([$feature->created_at, $history->min('created_at')])->filter()->min()],
            ['key' => 'in_progress', 'label' => 'In progress', 'at' => $reached('status', 'in_progress')],
            ['key' => 'implemented', 'label' => 'Implemented', 'at' => $reached('status', 'implemented')],
            // Latest, not first: verification can be lost and regained.
            ['key' => 'verified', 'label' => 'Verified', 'at' => $history->where('field', 'verification_status')->where('to_value', 'verified')->first()?->created_at],
        ];

        [$position, $state, $hint] = match ($feature->status) {
            FeatureStatus::Planned => [0, 'current', 'Not started yet'],
            FeatureStatus::InProgress => [1, 'current', 'Being built'],
            FeatureStatus::Blocked => [1, 'blocked', 'Blocked'],
            FeatureStatus::NotImplemented => [0, 'stopped', 'Marked not implemented'],
            FeatureStatus::Implemented => match ($feature->verification_status) {
                VerificationStatus::Verified => [3, 'done', 'Checked and working'],
                VerificationStatus::Failed => [3, 'failed', 'Verification failed'],
                default => [3, 'waiting', 'Waiting for verification'],
            },
        };

        foreach ($steps as $i => &$step) {
            $step['state'] = match (true) {
                $i < $position => 'done',
                $i === $position => $state,
                default => 'upcoming',
            };
            $step['hint'] = $i === $position ? $hint : null;
            if ($step['state'] === 'upcoming') {
                $step['at'] = null; // reached once, but no longer true (e.g. moved back)
            }
        }

        return $steps;
    }
}
