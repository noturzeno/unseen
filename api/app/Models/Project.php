<?php

namespace App\Models;

use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Concerns\HasPlatforms;
use App\Services\ProjectDashboard;
use Database\Factories\ProjectFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

#[Fillable(['name', 'description', 'repository_url', 'tech_stack', 'platforms', 'mismatches_enabled', 'actions_minutes_out_at'])]
class Project extends Model
{
    /** @use HasFactory<ProjectFactory> */
    use HasFactory;

    use HasPlatforms;

    protected $attributes = [
        'mismatches_enabled' => true,
    ];

    protected function casts(): array
    {
        return [
            'mismatches_enabled' => 'boolean',
            'archived_at' => 'datetime',
            'roadmap_synced_at' => 'datetime',
            'actions_minutes_out_at' => 'datetime',
        ];
    }

    /**
     * Whether to suggest running CI on a local Mac to this person: the project is flagged as out of
     * GitHub Actions minutes and they haven't dismissed it since it was (last) flagged.
     */
    public function showsCiNoticeTo(User $user): bool
    {
        if ($this->actions_minutes_out_at === null) {
            return false;
        }
        $dismissed = $this->workspaceFor($user)->ci_notice_dismissed_at;

        return $dismissed === null || $dismissed->lt($this->actions_minutes_out_at);
    }

    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'project_members')->withTimestamps();
    }

    public function featureCategories(): HasMany
    {
        return $this->hasMany(FeatureCategory::class)->orderBy('position')->orderBy('name');
    }

    public function agentRuns(): HasMany
    {
        return $this->hasMany(AgentRun::class);
    }

    public function invitations(): HasMany
    {
        return $this->hasMany(ProjectInvitation::class);
    }

    public function workspaces(): HasMany
    {
        return $this->hasMany(ProjectWorkspace::class);
    }

    /** This person's own folder and runner for the project (unsaved if they haven't set one up). */
    public function workspaceFor(User $user): ProjectWorkspace
    {
        return $this->workspaces()->firstOrNew(['user_id' => $user->id]);
    }

    public function roadmapPhases(): HasMany
    {
        return $this->hasMany(RoadmapPhase::class)->orderBy('position');
    }

    public function features(): HasMany
    {
        return $this->hasMany(Feature::class);
    }

    public function developmentReports(): HasMany
    {
        return $this->hasMany(DevelopmentReport::class);
    }

    public function repositoryScans(): HasMany
    {
        return $this->hasMany(RepositoryScan::class);
    }

    public function featureSuggestions(): HasMany
    {
        return $this->hasMany(FeatureSuggestion::class);
    }

    public function testRuns(): HasMany
    {
        return $this->hasMany(TestRun::class);
    }

    public function unresolvedItems(): HasMany
    {
        return $this->hasMany(UnresolvedItem::class);
    }

    public function tickets(): HasMany
    {
        return $this->hasMany(Ticket::class);
    }

    public function specMismatches(): HasMany
    {
        return $this->hasMany(SpecMismatch::class);
    }

    public function ticketComments(): HasMany
    {
        return $this->hasMany(TicketComment::class);
    }

    /** Two or more platforms: the dashboard splits progress per platform. */
    public function isMultiPlatform(): bool
    {
        return count($this->platformList()) > 1;
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    public function isOwnedBy(User $user): bool
    {
        return $this->owner_id === $user->id;
    }

    public function hasMember(User $user): bool
    {
        return $this->members()->whereKey($user->id)->exists();
    }

    /**
     * Adds the counts needed for list-level progress (see ProjectDashboard::progress).
     */
    public function scopeWithProgressCounts(Builder $query): void
    {
        $query->withCount([
            'features',
            'features as implemented_features_count' => fn (Builder $q) => $q->where('status', FeatureStatus::Implemented),
            'features as verified_features_count' => fn (Builder $q) => $q
                ->where('status', FeatureStatus::Implemented)
                ->where('verification_status', VerificationStatus::Verified),
            'features as attention_features_count' => fn (Builder $q) => ProjectDashboard::scopeNeedsAttention($q),
        ])->withMax('features', 'updated_at')->withMax('developmentReports', 'created_at');
    }

    /**
     * Latest change to the project, any of its features, or a new report.
     * Requires withProgressCounts().
     */
    public function lastActivityAt(): ?Carbon
    {
        return collect([$this->updated_at, $this->features_max_updated_at, $this->development_reports_max_created_at])
            ->filter()
            ->map(fn ($t) => Carbon::parse($t))
            ->max();
    }

    /**
     * Projects the user owns or has been explicitly granted access to.
     */
    public function scopeAccessibleBy(Builder $query, User $user): void
    {
        $query->where(fn (Builder $q) => $q
            ->where('owner_id', $user->id)
            ->orWhereHas('members', fn (Builder $m) => $m->whereKey($user->id)))
            ->when($user->tokenProjectId(), fn (Builder $q, int $only) => $q->whereKey($only));
    }
}
