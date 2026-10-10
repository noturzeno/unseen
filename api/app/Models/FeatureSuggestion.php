<?php

namespace App\Models;

use App\Enums\FeatureStatus;
use App\Enums\SuggestionStatus;
use Database\Factories\FeatureSuggestionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/**
 * Created and updated only by RepositoryScanService and SuggestionReviewService.
 */
class FeatureSuggestion extends Model
{
    /** @use HasFactory<FeatureSuggestionFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'status' => SuggestionStatus::class,
            'suggested_status' => FeatureStatus::class,
            'evidence' => 'array',
            'reviewed_at' => 'datetime',
        ];
    }

    /**
     * Normalised comparison key: lowercase words joined by "-", last word singular.
     * "Feature Categories" and "feature_category" both become "feature-category".
     */
    public static function normalizeKey(string $value): string
    {
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', Str::lower(Str::snake(trim($value)))), '-');
        $words = $slug === '' ? [] : explode('-', $slug);
        if ($words) {
            $words[count($words) - 1] = Str::singular(end($words));
        }

        return Str::limit(implode('-', $words), 100, '');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function matchedFeature(): BelongsTo
    {
        return $this->belongsTo(Feature::class, 'matched_feature_id');
    }

    public function lastScan(): BelongsTo
    {
        return $this->belongsTo(RepositoryScan::class, 'last_scan_id');
    }

    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
