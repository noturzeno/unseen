<?php

namespace App\Enums;

/**
 * What a person decided for a PRD vs design mismatch.
 */
enum MismatchDecision: string
{
    case FollowPrd = 'follow_prd';
    case FollowDesign = 'follow_design';
    case Other = 'other';

    public function label(): string
    {
        return match ($this) {
            self::FollowPrd => 'Follow the PRD',
            self::FollowDesign => 'Keep the design',
            self::Other => 'Something else',
        };
    }

    /** What the coding agent has to do next. */
    public function action(): string
    {
        return match ($this) {
            self::FollowPrd => 'Change the product to match the PRD.',
            self::FollowDesign => 'Keep the product as designed and update the PRD to match.',
            self::Other => 'Do what the decision note says, and update the PRD to match.',
        };
    }
}
