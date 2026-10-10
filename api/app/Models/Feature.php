<?php

namespace App\Models;

use App\Enums\FeatureStatus;
use App\Enums\Platform;
use App\Enums\VerificationStatus;
use App\Models\Concerns\HasPlatforms;
use Database\Factories\FeatureFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * status / verification_status are not fillable: change them through
 * FeatureStatusService so every change is recorded in the history.
 */
#[Fillable(['name', 'description', 'category_id', 'platforms'])]
class Feature extends Model
{
    /** @use HasFactory<FeatureFactory> */
    use HasFactory;

    use HasPlatforms;

    protected $attributes = [
        'status' => 'planned',
        'verification_status' => 'not_checked',
    ];

    protected function casts(): array
    {
        return [
            'status' => FeatureStatus::class,
            'verification_status' => VerificationStatus::class,
        ];
    }

    /**
     * Features that apply to the platform: tagged with it, or not tagged at all (every platform).
     */
    public function scopeOnPlatform(Builder $query, Platform $platform): void
    {
        $query->where(fn (Builder $q) => $q->whereNull('platforms')->orWhereJsonContains('platforms', $platform->value));
    }

    /** Applies to every platform of the project: no platform tagged. */
    public function isOnAllPlatforms(): bool
    {
        return $this->platformList() === [];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FeatureCategory::class, 'category_id');
    }

    public function acceptanceCriteria(): HasMany
    {
        return $this->hasMany(AcceptanceCriterion::class)->orderBy('position')->orderBy('id');
    }

    public function evidence(): HasMany
    {
        return $this->hasMany(FeatureEvidence::class)->latest('created_at')->latest('id');
    }

    /**
     * Scan suggestions accepted as or merged into this feature (repository evidence).
     */
    public function suggestions(): HasMany
    {
        return $this->hasMany(FeatureSuggestion::class);
    }

    public function checks(): HasMany
    {
        return $this->hasMany(FeatureCheck::class)->orderBy('id');
    }

    public function diagrams(): HasMany
    {
        return $this->hasMany(FeatureDiagram::class)->orderBy('type')->orderBy('id');
    }

    public function specMismatches(): HasMany
    {
        return $this->hasMany(SpecMismatch::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(FeatureStatusChange::class)->latest('created_at')->latest('id');
    }
}
