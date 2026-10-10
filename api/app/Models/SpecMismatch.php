<?php

namespace App\Models;

use App\Enums\BuiltTo;
use App\Enums\ClaimSource;
use App\Enums\MismatchDecision;
use App\Enums\MismatchStatus;
use App\Enums\WaitingOn;
use Database\Factories\SpecMismatchFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A place where the PRD and the design disagree. Recorded by the coding agent; decided by a person
 * (web UI, or a `write` token); carried out and marked applied by the agent. See SpecMismatchService.
 */
class SpecMismatch extends Model
{
    /** @use HasFactory<SpecMismatchFactory> */
    use HasFactory;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'built_to' => BuiltTo::class,
            'decide_by' => WaitingOn::class,
            'status' => MismatchStatus::class,
            'decision' => MismatchDecision::class,
            'source' => ClaimSource::class,
            'decided_at' => 'datetime',
            'applied_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function feature(): BelongsTo
    {
        return $this->belongsTo(Feature::class);
    }

    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
