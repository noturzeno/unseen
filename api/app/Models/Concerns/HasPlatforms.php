<?php

namespace App\Models\Concerns;

use App\Enums\Platform;
use Illuminate\Database\Eloquent\Casts\Attribute;

/**
 * A `platforms` JSON column holding Platform values. Stored in enum order without duplicates;
 * an empty list is stored as null.
 */
trait HasPlatforms
{
    protected function platforms(): Attribute
    {
        return Attribute::make(
            get: fn (?string $value) => $value === null ? null : array_map(fn (Platform $p) => $p->value, Platform::listFrom(json_decode($value, true))),
            set: fn (?array $value) => ($list = Platform::listFrom($value))
                ? json_encode(array_map(fn (Platform $p) => $p->value, $list))
                : null,
        );
    }

    /** @return list<Platform> */
    public function platformList(): array
    {
        return Platform::listFrom($this->platforms);
    }
}
