<?php

namespace App\Models;

use App\Enums\ClaimSource;
use App\Enums\DiagramType;
use Database\Factories\FeatureDiagramFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * source (who wrote it) is not fillable: callers set it from how the request was made.
 */
#[Fillable(['type', 'title', 'definition'])]
class FeatureDiagram extends Model
{
    /** @use HasFactory<FeatureDiagramFactory> */
    use HasFactory;

    public const MAX_DEFINITION = 20000;

    protected function casts(): array
    {
        return [
            'type' => DiagramType::class,
            'source' => ClaimSource::class,
        ];
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
