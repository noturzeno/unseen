<?php

namespace App\Models;

use App\Enums\ClaimSource;
use App\Enums\WaitingOn;
use Database\Factories\UnresolvedItemFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Created by DevelopmentReportService; closed/reopened by UnresolvedItemService.
 */
class UnresolvedItem extends Model
{
    /** @use HasFactory<UnresolvedItemFactory> */
    use HasFactory;

    public const OPEN = 'open';

    public const RESOLVED = 'resolved';

    /** Closed by a person without being done (reason in resolution_note). Never handed to the agent. */
    public const IGNORED = 'ignored';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'resolved_at' => 'datetime',
            'resolved_source' => ClaimSource::class,
            'waiting_on' => WaitingOn::class,
            'decided_at' => 'datetime',
        ];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DevelopmentReport::class, 'development_report_id');
    }

    public function resolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /** The person who answered the item's question (`decision`) on the Unresolved page. */
    public function decidedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function resolvedReport(): BelongsTo
    {
        return $this->belongsTo(DevelopmentReport::class, 'resolved_report_id');
    }

    /** Open and waiting on the client or the team, so the coding agent can't finish it alone. */
    public function needsDecision(): bool
    {
        return $this->isOpen() && $this->waiting_on?->needsDecision();
    }

    public function isOpen(): bool
    {
        return $this->status === self::OPEN;
    }
}
