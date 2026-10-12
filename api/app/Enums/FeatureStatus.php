<?php

namespace App\Enums;

/**
 * Implementation status: what has been built. Independent of verification.
 *
 * `ignored` is a person's decision that the feature won't be built. Only people set or lift it
 * (on the feature page); agents can't, so a report can't quietly bring it back into scope.
 */
enum FeatureStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Implemented = 'implemented';
    case Blocked = 'blocked';
    case NotImplemented = 'not_implemented';
    case Ignored = 'ignored';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::Implemented => 'Implemented',
            self::Blocked => 'Blocked',
            self::NotImplemented => 'Not implemented',
            self::Ignored => 'Ignored',
        };
    }

    /**
     * Statuses an agent may set (everything but the human-only `ignored`).
     *
     * @return list<self>
     */
    public static function agentSettable(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s !== self::Ignored));
    }
}
