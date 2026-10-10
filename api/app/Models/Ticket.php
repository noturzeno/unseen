<?php

namespace App\Models;

use App\Enums\ClaimSource;
use App\Enums\CommentRole;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use Database\Factories\TicketFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * A work ticket and where it is in the flow. Written by TicketService; the web UI only shows it.
 */
class Ticket extends Model
{
    /** @use HasFactory<TicketFactory> */
    use HasFactory;

    public const PRIORITIES = ['low', 'medium', 'high', 'urgent'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'type' => TicketType::class,
            'status' => TicketStatus::class,
            'raised_by' => CommentRole::class,
            'source' => ClaimSource::class,
            'closed_at' => 'datetime',
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

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** The thread, oldest first. */
    public function comments(): HasMany
    {
        return $this->hasMany(TicketComment::class)->oldest()->oldest('id');
    }

    /** Latest comment from QA or the client, for lists. */
    public function latestFeedback(): HasOne
    {
        return $this->hasOne(TicketComment::class)->ofMany(['id' => 'max'], fn (Builder $q) => $q
            ->whereIn('author_role', CommentRole::feedback())->whereNotNull('body'));
    }

    public function scopeOpen(Builder $query): void
    {
        $query->whereIn('status', TicketStatus::open());
    }

    /** "WEB-142" or "#12". */
    public function label(): string
    {
        return $this->reference ?: '#'.$this->id;
    }
}
