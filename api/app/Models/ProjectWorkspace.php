<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One person's checkout of a project: the folder on their computer that "Proceed" opens, and the
 * `unseen runner` they run there. Every member sets their own.
 */
#[Fillable(['user_id', 'local_path', 'runner_seen_at', 'runner_name'])]
class ProjectWorkspace extends Model
{
    protected function casts(): array
    {
        return ['runner_seen_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The "Start runner" button: an unseen:// link that the CLI handles on this person's computer. */
    public function startRunnerUrl(): string
    {
        return 'unseen://runner'.($this->local_path ? '?folder='.rawurlencode($this->local_path) : '');
    }

    /** The runner checked in within the last minute. */
    public function runnerOnline(): bool
    {
        return $this->runner_seen_at !== null && $this->runner_seen_at->gt(now()->subMinute());
    }
}
