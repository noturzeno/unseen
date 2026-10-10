<?php

namespace App\Enums;

/**
 * Implementation status: what has been built. Independent of verification.
 */
enum FeatureStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Implemented = 'implemented';
    case Blocked = 'blocked';
    case NotImplemented = 'not_implemented';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::Implemented => 'Implemented',
            self::Blocked => 'Blocked',
            self::NotImplemented => 'Not implemented',
        };
    }
}
