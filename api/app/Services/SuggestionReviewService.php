<?php

namespace App\Services;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Enums\SuggestionStatus;
use App\Models\Feature;
use App\Models\FeatureSuggestion;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Human review of scan suggestions. These are the only ways a suggestion becomes, or
 * joins, a feature. Accepted features are recorded as `user_entered`, because a person
 * confirmed them, and keep the scan evidence attached through the suggestion.
 */
class SuggestionReviewService
{
    public function __construct(private readonly FeatureStatusService $statuses) {}

    /**
     * @param  array{name?: string, description?: ?string, category_id?: ?int, status?: string}  $edits
     */
    public function accept(FeatureSuggestion $suggestion, array $edits, User $user): Feature
    {
        $this->ensurePending($suggestion);

        return DB::transaction(function () use ($suggestion, $edits, $user) {
            $feature = $suggestion->project->features()->make([
                'name' => $edits['name'] ?? $suggestion->name,
                'description' => array_key_exists('description', $edits) ? $edits['description'] : $suggestion->description,
                'category_id' => array_key_exists('category_id', $edits) ? $edits['category_id'] : $this->suggestedCategoryId($suggestion),
            ]);

            // Defaults: what the project's docs said (suggested_status), else implemented (code exists).
            // Either way an implemented feature starts as needs_verification.
            $this->statuses->apply(
                $feature,
                FeatureStatus::from($edits['status'] ?? $suggestion->suggested_status?->value ?? FeatureStatus::Implemented->value),
                null,
                ClaimSource::AgentReported,
                $user,
                'Accepted from a repository scan suggestion.',
            );

            $this->markReviewed($suggestion, SuggestionStatus::Accepted, $feature, $user);

            return $feature;
        });
    }

    /**
     * The category named by the suggestion (e.g. the doc section it came from), found
     * case-insensitively or created.
     */
    private function suggestedCategoryId(FeatureSuggestion $suggestion): ?int
    {
        $name = trim((string) $suggestion->suggested_category);
        if ($name === '') {
            return null;
        }

        $project = $suggestion->project;

        return ($project->featureCategories()->whereRaw('lower(name) = ?', [mb_strtolower($name)])->first()
            ?? $project->featureCategories()->create(['name' => mb_strimwidth($name, 0, 255)]))->id;
    }

    public function merge(FeatureSuggestion $suggestion, Feature $feature, User $user): Feature
    {
        $this->ensurePending($suggestion);

        if ($feature->project_id !== $suggestion->project_id) {
            throw ValidationException::withMessages(['feature_id' => ['The feature must belong to the same project.']]);
        }

        $this->markReviewed($suggestion, SuggestionStatus::Merged, $feature, $user);
        $feature->touch();

        return $feature;
    }

    public function dismiss(FeatureSuggestion $suggestion, User $user): void
    {
        $this->ensurePending($suggestion);
        $this->markReviewed($suggestion, SuggestionStatus::Dismissed, null, $user);
    }

    /**
     * Undo a dismissal. Accepted/merged suggestions cannot be restored, since the feature now exists.
     */
    public function restore(FeatureSuggestion $suggestion): void
    {
        if ($suggestion->status !== SuggestionStatus::Dismissed) {
            throw ValidationException::withMessages(['suggestion' => ['Only dismissed suggestions can be restored.']]);
        }

        $suggestion->update(['status' => SuggestionStatus::Pending, 'reviewed_by' => null, 'reviewed_at' => null]);
    }

    private function ensurePending(FeatureSuggestion $suggestion): void
    {
        if ($suggestion->status !== SuggestionStatus::Pending) {
            throw ValidationException::withMessages(['suggestion' => ['This suggestion has already been reviewed.']]);
        }
    }

    private function markReviewed(FeatureSuggestion $suggestion, SuggestionStatus $status, ?Feature $feature, User $user): void
    {
        $suggestion->update([
            'status' => $status,
            'feature_id' => $feature?->id,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);
    }
}
