<?php

namespace App\Models;

use App\Enums\ClaimSource;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['field', 'from_value', 'to_value', 'source', 'user_id', 'note'])]
class FeatureStatusChange extends Model
{
    public const UPDATED_AT = null;

    protected $table = 'feature_status_history';

    protected function casts(): array
    {
        return [
            'source' => ClaimSource::class,
            'created_at' => 'datetime',
        ];
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
