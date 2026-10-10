<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a phase's implementation list, optionally linked to a tracked feature.
 */
#[Fillable(['text', 'done', 'feature_id', 'position'])]
class RoadmapItem extends Model
{
    protected function casts(): array
    {
        return ['done' => 'boolean'];
    }

    public function phase(): BelongsTo
    {
        return $this->belongsTo(RoadmapPhase::class, 'roadmap_phase_id');
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
