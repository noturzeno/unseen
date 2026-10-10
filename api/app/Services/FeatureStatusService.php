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
