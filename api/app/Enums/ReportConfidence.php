<?php

namespace App\Enums;

/**
 * The reporter's own confidence in the work. Self-assessed, never treated as verification.
 */
enum ReportConfidence: string
{
    case Low = 'low';
    case Medium = 'medium';
    case High = 'high';

    public function label(): string
    {
        return ucfirst($this->value);
    }
}
