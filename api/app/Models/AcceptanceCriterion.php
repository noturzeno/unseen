<?php

namespace App\Models;

use App\Enums\ClaimSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['description', 'position', 'source'])]
class AcceptanceCriterion extends Model
{
    protected $table = 'feature_acceptance_criteria';

    protected function casts(): array
    {
        return [
            'source' => ClaimSource::class,
        ];
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }
}
