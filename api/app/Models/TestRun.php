<?php

namespace App\Models;

use Database\Factories\TestRunFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Created only by VerificationService.
 */
class TestRun extends Model
{
    /** @use HasFactory<TestRunFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'git_dirty' => 'boolean',
            'feature_outcomes' => 'array',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function checkResults(): HasMany
    {
        return $this->hasMany(TestRunCheckResult::class);
    }
}
