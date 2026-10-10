<?php

namespace App\Enums;

/**
 * Status of a roadmap phase, as the coding agent reports it. "done" is a claim about the plan,
 * not verification: linked features keep their own verification status.
 */
enum PhaseStatus: string
{
    case Planned = 'planned';
    case InProgress = 'in_progress';
    case Done = 'done';
    case Blocked = 'blocked';

    public function label(): string
    {
        return match ($this) {
            self::Planned => 'Planned',
            self::InProgress => 'In progress',
            self::Done => 'Done',
            self::Blocked => 'Blocked',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Planned => '',
            self::InProgress => 'info',
            self::Done => 'ok',
            self::Blocked => 'warn',
        };
    }
}
