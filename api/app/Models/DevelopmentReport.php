<?php

namespace App\Models;

use App\Enums\ClaimSource;
use App\Enums\ReportConfidence;
use Database\Factories\DevelopmentReportFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Create through DevelopmentReportService so feature claims are applied consistently.
 */
#[Fillable([
    'agent', 'prompt', 'summary', 'confidence', 'limitations', 'git_branch', 'git_commit', 'diff_ref',
    'changed_files', 'tests', 'unresolved', 'reported_at',
])]
class DevelopmentReport extends Model
{
    /** @use HasFactory<DevelopmentReportFactory> */
    use HasFactory;

    protected function casts(): array
    {
        return [
            'source' => ClaimSource::class,
            'confidence' => ReportConfidence::class,
            'changed_files' => 'array',
            'tests' => 'array',
            'unresolved' => 'array',
            'reported_at' => 'datetime',
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

    public function evidence(): HasMany
    {
        return $this->hasMany(FeatureEvidence::class);
    }

    /** Ticket updates this report made (status changes and notes). */
    public function ticketUpdates(): HasMany
    {
        return $this->hasMany(TicketComment::class)->oldest('id');
    }

    public function unresolvedItems(): HasMany
    {
        return $this->hasMany(UnresolvedItem::class)->orderBy('id');
    }

    public function features(): BelongsToMany
    {
        return $this->belongsToMany(Feature::class, 'feature_evidence')
            ->withPivot(['claimed_status', 'note']);
    }

    /**
     * @return array<string, int> outcome => count
     */
    public function testCounts(): array
    {
        return collect($this->tests ?? [])->countBy('outcome')->all();
    }
}
