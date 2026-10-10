<?php

namespace App\Enums;

/**
 * Where a PRD vs design mismatch is: waiting for a decision, decided (the agent still has to carry
 * it out), or applied (code or PRD changed to match the decision).
 */
enum MismatchStatus: string
{
    case Open = 'open';
    case Decided = 'decided';
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Needs a decision',
            self::Decided => 'Decided, to apply',
            self::Applied => 'Applied',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open => 'warn',
            self::Decided => 'info',
            self::Applied => 'ok',
        };
    }
}
