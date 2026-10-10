<?php

namespace App\Enums;

/**
 * Verification status: whether the behaviour has been confirmed to work.
 */
enum VerificationStatus: string
{
    case NotChecked = 'not_checked';
    case NeedsVerification = 'needs_verification';
    case Verified = 'verified';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::NotChecked => 'Not checked',
            self::NeedsVerification => 'Needs verification',
            self::Verified => 'Verified',
            self::Failed => 'Failed',
        };
    }
}
