<?php

namespace App\Models;

use App\Enums\ClaimSource;
use App\Enums\FeatureStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['feature_id', 'development_report_id', 'kind', 'source', 'claimed_status', 'note'])]
class FeatureEvidence extends Model
{
    public const KIND_DEVELOPMENT_REPORT = 'development_report';

    protected $table = 'feature_evidence';

    protected function casts(): array
    {
        return [
            'source' => ClaimSource::class,
            'claimed_status' => FeatureStatus::class,
        ];
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function developmentReport(): BelongsTo
    {
        return $this->belongsTo(DevelopmentReport::class);
    }
}
