<?php

namespace App\Models;

use App\Enums\ClaimSource;
use App\Enums\CommentRole;
use App\Enums\TicketStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One entry in a ticket's thread: a comment, a status change, or both (a QA fail with its reason).
 * `author_role` says whose words they are; `user_id` and `source` say who recorded them.
 */
class TicketComment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'author_role' => CommentRole::class,
            'from_status' => TicketStatus::class,
            'to_status' => TicketStatus::class,
            'source' => ClaimSource::class,
        ];
    }

    public function ticket(): BelongsTo
    {
        return $this->belongsTo(Ticket::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function report(): BelongsTo
    {
        return $this->belongsTo(DevelopmentReport::class, 'development_report_id');
    }

    public function isStatusChange(): bool
    {
        return $this->to_status !== null;
    }
}
