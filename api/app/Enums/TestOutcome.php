<?php

namespace App\Enums;

enum TestOutcome: string
{
    case Passed = 'passed';
    case Failed = 'failed';
    case Error = 'error';
    case Skipped = 'skipped';
    case NotRun = 'not_run';

    public function label(): string
    {
        return match ($this) {
            self::Passed => 'Passed',
            self::Failed => 'Failed',
            self::Error => 'Error',
            self::Skipped => 'Skipped',
            self::NotRun => 'Not run',
        };
    }
}
