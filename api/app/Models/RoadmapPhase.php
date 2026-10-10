<?php

namespace App\Models;

use App\Enums\ClaimSource;
use App\Enums\PhaseStatus;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One phase of a project's roadmap. Written only by RoadmapService::sync (source is set there).
 */
#[Fillable(['key', 'name', 'description', 'status', 'target_date', 'position'])]
class RoadmapPhase extends Model
{
    protected function casts(): array
    {
        return [
            'status' => PhaseStatus::class,
            'source' => ClaimSource::class,
            'target_date' => 'date',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(RoadmapItem::class)->orderBy('position');
    }
}
