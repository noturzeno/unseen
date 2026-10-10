<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['test_run_id', 'feature_check_id', 'feature_id', 'outcome', 'matched', 'failed', 'failures'])]
class TestRunCheckResult extends Model
{
    public const UPDATED_AT = null;

    protected function casts(): array
    {
        return [
            'failures' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public function testRun(): BelongsTo
    {
        return $this->belongsTo(TestRun::class);
    }

    public function check(): BelongsTo
    {
        return $this->belongsTo(FeatureCheck::class, 'feature_check_id');
    }
}
