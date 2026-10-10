<?php

namespace App\Models;

use Database\Factories\FeatureCheckFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

#[Fillable(['pattern', 'description', 'created_by'])]
class FeatureCheck extends Model
{
    /** @use HasFactory<FeatureCheckFactory> */
    use HasFactory;

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function results(): HasMany
    {
        return $this->hasMany(TestRunCheckResult::class);
    }

    public function latestResult(): HasOne
    {
        return $this->hasOne(TestRunCheckResult::class)->latestOfMany();
    }

    /**
     * Does this check's pattern select the given test case?
     *  - with "*": glob over the case id ("Class::method") or file path
     *  - without: exact id/file, or every method of a class ("Tests\Feature\InvoiceTest")
     */
    public function matches(string $caseId, ?string $file = null): bool
    {
        $pattern = $this->pattern;

        if (str_contains($pattern, '*')) {
            $regex = '/^'.str_replace('\*', '.*', preg_quote($pattern, '/')).'$/';

            return (bool) preg_match($regex, $caseId) || ($file !== null && (bool) preg_match($regex, $file));
        }

        return $caseId === $pattern
            || str_starts_with($caseId, $pattern.'::')
            || ($file !== null && ($file === $pattern || str_ends_with($file, '/'.ltrim($pattern, '/'))));
    }
}
