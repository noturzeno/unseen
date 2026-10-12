<?php

namespace App\Services;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Single entry point for changing a feature's implementation or verification
 * status. Applies the transition rules and records every change in history.
 *
 * Rules:
 *  1. `verified` is only allowed while the feature is `implemented`.
 *  2. Changing the implementation status of a `verified` feature resets
 *     verification to `needs_verification` (the old check no longer applies).
 *  3. A feature becoming `implemented` while `not_checked` moves to
 *     `needs_verification`, so unverified work stays visible.
 *  4. `ignored` is a person's decision: only a user (ClaimSource::UserEntered) can move a
 *     feature into or out of it. Agents, scans and test runs can't bring it back into scope.
 * Rules 2 and 3 apply only when the caller does not set verification explicitly.
 */
class FeatureStatusService
{
    public function apply(
        Feature $feature,
        ?FeatureStatus $status,
        ?VerificationStatus $verification,
        ClaimSource $source,
        ?User $user = null,
        ?string $note = null,
    ): Feature {
        $isNew = ! $feature->exists;
        $oldStatus = $isNew ? null : $feature->status;
        $oldVerification = $isNew ? null : $feature->verification_status;

        $newStatus = $status ?? $feature->status ?? FeatureStatus::Planned;
        $newVerification = $verification ?? $feature->verification_status ?? VerificationStatus::NotChecked;
        $autoNote = null;

        if ($verification === null) {
            if (! $isNew && $newStatus !== $oldStatus && $oldVerification === VerificationStatus::Verified) {
                $newVerification = VerificationStatus::NeedsVerification;
                $autoNote = 'Verification reset because the implementation status changed.';
            } elseif ($newStatus === FeatureStatus::Implemented && $newVerification === VerificationStatus::NotChecked) {
                $newVerification = VerificationStatus::NeedsVerification;
                $autoNote = 'Implemented features need verification.';
            }
        }

        if ($newStatus !== $oldStatus && in_array(FeatureStatus::Ignored, [$oldStatus, $newStatus], true) && $source !== ClaimSource::UserEntered) {
            throw ValidationException::withMessages([
                'status' => ['This feature is ignored: a person decided it won\'t be built. Only a person can change that, on the feature page in Unseen.'],
            ]);
        }

        if ($newVerification === VerificationStatus::Verified && $newStatus !== FeatureStatus::Implemented) {
            throw ValidationException::withMessages([
                'verification_status' => ['A feature can only be verified when its status is implemented.'],
            ]);
        }

        return DB::transaction(function () use ($feature, $oldStatus, $oldVerification, $newStatus, $newVerification, $source, $user, $note, $autoNote) {
            $feature->status = $newStatus;
            $feature->verification_status = $newVerification;
            $feature->save();

            if ($newStatus !== $oldStatus) {
                $this->record($feature, 'status', $oldStatus?->value, $newStatus->value, $source, $user, $note);
            }

            if ($newVerification !== $oldVerification) {
                $this->record($feature, 'verification_status', $oldVerification?->value, $newVerification->value, $source, $user,
                    collect([$note, $autoNote])->filter()->implode(' ') ?: null);
            }

            return $feature;
        });
    }

    /**
     * Records a person's decision that the feature won't be built. The reason is kept in the history.
     */
    public function ignore(Feature $feature, User $user, string $reason): Feature
    {
        return $this->apply($feature, FeatureStatus::Ignored, null, ClaimSource::UserEntered, $user, $reason);
    }

    /**
     * Brings an ignored feature back into scope, at the status it had before it was ignored.
     */
    public function restore(Feature $feature, User $user): Feature
    {
        $before = $feature->statusHistory()->where('field', 'status')->where('to_value', FeatureStatus::Ignored->value)->first()?->from_value;

        return $this->apply($feature, FeatureStatus::tryFrom((string) $before) ?? FeatureStatus::Planned, null, ClaimSource::UserEntered, $user, 'Back in scope.');
    }

    private function record(Feature $feature, string $field, ?string $from, string $to, ClaimSource $source, ?User $user, ?string $note): void
    {
        $feature->statusHistory()->create([
            'field' => $field,
            'from_value' => $from,
            'to_value' => $to,
            'source' => $source,
            'user_id' => $user?->id,
            'note' => $note,
        ]);
    }
}
