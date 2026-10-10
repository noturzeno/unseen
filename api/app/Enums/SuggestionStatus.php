<?php

namespace App\Enums;

enum SuggestionStatus: string
{
    case Pending = 'pending';
    case Accepted = 'accepted';
    case Merged = 'merged';
    case Dismissed = 'dismissed';

    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Waiting for review',
            self::Accepted => 'Accepted as new feature',
            self::Merged => 'Merged into feature',
            self::Dismissed => 'Dismissed',
        };
    }
}
