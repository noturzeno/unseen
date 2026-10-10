<?php

namespace App\Enums;

/** Which source the current implementation follows for a mismatch. */
enum BuiltTo: string
{
    case Design = 'design';
    case Prd = 'prd';
    case Neither = 'neither';
    case NotBuilt = 'not_built';

    public function label(): string
    {
        return match ($this) {
            self::Design => 'Built to the design',
            self::Prd => 'Built to the PRD',
            self::Neither => 'Built to neither',
            self::NotBuilt => 'Not built yet',
        };
    }
}
