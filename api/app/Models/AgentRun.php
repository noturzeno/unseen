<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A "Proceed" request: Unseen built a prompt for the project's coding agent, and `unseen runner`
 * on the developer's computer opens Claude Code with it. Created by AgentRunService only.
 */
#[Fillable(['kind', 'ref', 'title', 'prompt', 'status', 'runner_name', 'error', 'claimed_at', 'finished_at'])]
class AgentRun extends Model
{
    public const QUEUED = 'queued';

    public const CLAIMED = 'claimed';

    public const LAUNCHED = 'launched';

    public const FAILED = 'failed';

    public const EXPIRED = 'expired';

    /** A runner must pick a request up within this many minutes, or it expires. */
    public const EXPIRES_AFTER_MINUTES = 10;

    protected function casts(): array
    {
        return ['claimed_at' => 'datetime', 'finished_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
