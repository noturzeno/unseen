<?php

namespace App\Enums;

/**
 * Kinds of feature diagram. All are written in Mermaid; Mermaid has no native use-case
 * diagram, so agents draw use cases as a flowchart (actors, then use cases inside the system).
 */
enum DiagramType: string
{
    case Flowchart = 'flowchart';
    case UseCase = 'use_case';
    case Sequence = 'sequence';

    public function label(): string
    {
        return match ($this) {
            self::Flowchart => 'Flowchart',
            self::UseCase => 'Use case',
            self::Sequence => 'Sequence',
        };
    }
}
