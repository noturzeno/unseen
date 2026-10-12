<?php

namespace App\Services;

use App\Enums\CommentRole;
use App\Enums\FeatureStatus;
use App\Enums\Platform;
use App\Enums\SuggestionStatus;
use App\Enums\TicketStatus;
use App\Enums\VerificationStatus;
use App\Enums\WaitingOn;
use App\Models\AgentRun;
use App\Models\DevelopmentReport;
use App\Models\Feature;
use App\Models\FeatureStatusChange;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\UnresolvedItem;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Builds the project dashboard. Shared by the API and the web UI so both show the same numbers.
 *
 * Progress calculation (documented in README "Progress calculation"):
 *   implemented_percent = floor(features with status `implemented` / all tracked features * 100)
 *   verified_percent    = floor(features `implemented` AND `verified` / all tracked features * 100)
 * Every feature counts, whatever its status, except `ignored` ones: a person decided they won't
 * be built, so they are left out of the totals and progress. Percentages are rounded down so a project
 * never shows 100% before every feature qualifies. With no features, both are null
 * (unknown), not 0 or 100.
 *
 * forPlatform() narrows every feature number to one platform: features tagged with it plus
 * untagged ones (they apply to every platform). Tickets, reports and unresolved work stay project-wide.
 */
class ProjectDashboard
{
    private ?Platform $platform = null;

    public function forPlatform(?Platform $platform): static
    {
        $copy = clone $this;
        $copy->platform = $platform;

        return $copy;
    }

    /** The project's features, narrowed to the platform when one is set. */
    private function features(Project $project): HasMany
    {
        return $project->features()->when($this->platform, fn ($q, Platform $p) => $q->onPlatform($p));
    }

    private function featureScope(Project $project): \Closure
    {
        return fn (Builder $q) => $q->where('project_id', $project->id)->when($this->platform, fn ($q, Platform $p) => $q->onPlatform($p));
    }

    /**
     * @return array{implemented_percent: int|null, verified_percent: int|null}
     */
    public static function progress(int $implemented, int $verified, int $total): array
    {
        return [
            'implemented_percent' => $total > 0 ? intdiv($implemented * 100, $total) : null,
            'verified_percent' => $total > 0 ? intdiv($verified * 100, $total) : null,
        ];
    }

    /**
     * Features needing a human look: verification failed, blocked, or awaiting verification.
     */
    public static function scopeNeedsAttention(Builder $query): Builder
    {
        return $query->whereNot('status', FeatureStatus::Ignored)->where(fn (Builder $q) => $q
            ->where('status', FeatureStatus::Blocked)
            ->orWhereIn('verification_status', [VerificationStatus::Failed, VerificationStatus::NeedsVerification]));
    }

    public function summary(Project $project): array
    {
        $byStatus = $this->countBy($project, 'status', FeatureStatus::cases());
        $byVerification = $this->countBy($project, 'verification_status', VerificationStatus::cases());

        $total = array_sum($byStatus) - $byStatus[FeatureStatus::Ignored->value];
        $implemented = $byStatus[FeatureStatus::Implemented->value];
        $verified = $this->features($project)
            ->where('status', FeatureStatus::Implemented)
            ->where('verification_status', VerificationStatus::Verified)
            ->count();

        return [
            'platform' => $this->platform?->value,
            'total' => $total,
            'by_status' => $byStatus,
            'by_verification_status' => $byVerification,
            'implemented_and_verified' => $verified,
            'needs_attention' => self::scopeNeedsAttention($this->features($project)->getQuery())->count(),
            'progress' => self::progress($implemented, $verified, $total),
            'by_platform' => $this->byPlatform($project),
            'reports' => $project->developmentReports()->count(),
            'open_unresolved' => $project->unresolvedItems()->where('status', UnresolvedItem::OPEN)->count(),
            'unresolved_waiting_on' => $this->waitingOnCounts($project),
            'implemented_without_checks' => $this->features($project)->where('status', FeatureStatus::Implemented)->doesntHave('checks')->count(),
            'latest_test_run' => ($run = $project->testRuns()->latest()->latest('id')->first()) ? [
                'id' => $run->id,
                'git_commit' => $run->git_commit,
                'totals' => ['total' => $run->total, 'passed' => $run->passed, 'failed' => $run->failed, 'skipped' => $run->skipped],
                'created_at' => $run->created_at,
            ] : null,
            'pending_suggestions' => $project->featureSuggestions()->where('status', SuggestionStatus::Pending)->count(),
            'tickets' => $this->ticketCounts($project),
            // Null when the project has PRD vs design turned off.
            'mismatches' => $project->mismatches_enabled ? app(SpecMismatchService::class)->counts($project) : null,
            'last_report_at' => ($last = $project->developmentReports()->max('reported_at')) ? Carbon::parse($last) : null,
        ];
    }

    /**
     * @return Collection<int, Feature>
     */
    public function attention(Project $project, int $limit = 10): Collection
    {
        return self::scopeNeedsAttention($this->features($project)->getQuery())
            ->with('category')
            ->orderByRaw("case when verification_status = 'failed' then 0 when status = 'blocked' then 1 else 2 end")
            ->latest('updated_at')
            ->limit($limit)
            ->get();
    }

    /**
     * @return Collection<int, Feature>
     */
    public function recentlyUpdated(Project $project, int $limit = 10): Collection
    {
        return $this->features($project)->with('category')->latest('updated_at')->latest('id')->limit($limit)->get();
    }

    /**
     * @return Collection<int, FeatureStatusChange>
     */
    public function recentChanges(Project $project, int $limit = 10): Collection
    {
        return FeatureStatusChange::query()
            ->whereHas('feature', $this->featureScope($project))
            ->with(['feature:id,name', 'user:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->limit($limit)
            ->get();
    }

    /**
     * Implemented features with no automated checks: they can only be verified by hand.
     *
     * @return Collection<int, Feature>
     */
    public function withoutChecks(Project $project, int $limit = 10): Collection
    {
        return $this->features($project)->where('status', FeatureStatus::Implemented)->doesntHave('checks')
            ->orderBy('name')->limit($limit)->get();
    }

    /**
     * @return Collection<int, DevelopmentReport>
     */
    public function recentReports(Project $project, int $limit = 5): Collection
    {
        return $project->developmentReports()->with('user:id,name')->withCount('features')
            ->latest('reported_at')->latest('id')->limit($limit)->get();
    }

    /**
     * Open unresolved work from reports, newest first. Stays open until a person
     * (or a later report) closes it.
     *
     * Items waiting on the client or the team come first: someone has to act on those.
     *
     * @return Collection<int, array{id: int, text: string, waiting_on: ?WaitingOn, decision_note: ?string, decision: ?string, report_id: ?int, reported_at: \DateTimeInterface}>
     */
    public function unresolved(Project $project, int $limit = 10): Collection
    {
        return $project->unresolvedItems()
            ->where('status', UnresolvedItem::OPEN)
            ->orderByRaw("case waiting_on when 'team' then 0 when 'client' then 1 when 'agent' then 3 else 2 end")
            ->latest()->latest('id')->limit($limit)
            ->get()
            ->map(fn (UnresolvedItem $item) => [
                'id' => $item->id,
                'text' => $item->text,
                'waiting_on' => $item->waiting_on,
                'decision_note' => $item->decision_note,
                'decision' => $item->decision,
                'report_id' => $item->development_report_id,
                'reported_at' => $item->created_at,
            ]);
    }

    /**
     * Open unresolved items by who they wait on: client, team, agent, and unsorted.
     *
     * @return array{client: int, team: int, agent: int, unsorted: int}
     */
    public function waitingOnCounts(Project $project): array
    {
        $counts = $project->unresolvedItems()->where('status', UnresolvedItem::OPEN)->toBase()
            ->selectRaw('waiting_on, count(*) as aggregate')->groupBy('waiting_on')->pluck('aggregate', 'waiting_on');

        return [
            ...collect(WaitingOn::cases())->mapWithKeys(fn (WaitingOn $w) => [$w->value => (int) ($counts[$w->value] ?? 0)])->all(),
            'unsorted' => (int) ($counts[''] ?? 0),
        ];
    }

    /**
     * Tickets per status (every status present, zero when none), plus open, sent back and total.
     *
     * @return array{total: int, open: int, sent_back: int, by_status: array<string, int>}
     */
    public function ticketCounts(Project $project): array
    {
        $counts = $project->tickets()->toBase()->selectRaw('status, count(*) as aggregate')->groupBy('status')->pluck('aggregate', 'status');
        $byStatus = collect(TicketStatus::cases())->mapWithKeys(fn (TicketStatus $s) => [$s->value => (int) ($counts[$s->value] ?? 0)])->all();
        $sum = fn (array $statuses) => collect($statuses)->sum(fn (TicketStatus $s) => $byStatus[$s->value]);

        return [
            'total' => array_sum($byStatus),
            'open' => $sum(TicketStatus::open()),
            'sent_back' => $sum([TicketStatus::QaFailed, TicketStatus::ChangesRequested]),
            'by_status' => $byStatus,
        ];
    }

    /**
     * Open tickets: those QA or the client sent back first, then the most recently updated.
     *
     * @return Collection<int, Ticket>
     */
    public function openTickets(Project $project, int $limit = 10): Collection
    {
        return $project->tickets()->open()->with(['feature:id,name', 'latestFeedback'])->withCount('comments')
            ->orderByRaw("case when status in ('qa_failed', 'changes_requested') then 0 else 1 end")
            ->latest('updated_at')->latest('id')->limit($limit)->get();
    }

    /**
     * Latest comments from QA and the client, newest first.
     *
     * @return Collection<int, TicketComment>
     */
    public function recentFeedback(Project $project, int $limit = 8): Collection
    {
        return $project->ticketComments()->whereIn('author_role', CommentRole::feedback())->whereNotNull('body')
            ->with('ticket:id,project_id,reference,title,status')
            ->latest('created_at')->latest('id')->limit($limit)->get();
    }

    /**
     * Progress per category, uncategorised last. Same definitions as progress():
     * "verified" means implemented AND verified.
     *
     * @return Collection<int, array{id: string, name: string, total: int, implemented: int, verified: int}>
     */
    public function byCategory(Project $project): Collection
    {
        $implemented = FeatureStatus::Implemented->value;
        $verified = VerificationStatus::Verified->value;

        $rows = $this->features($project)
            ->whereNot('status', FeatureStatus::Ignored)
            ->toBase()
            ->selectRaw('category_id, count(*) as total')
            ->selectRaw('sum(case when status = ? then 1 else 0 end) as implemented', [$implemented])
            ->selectRaw('sum(case when status = ? and verification_status = ? then 1 else 0 end) as verified', [$implemented, $verified])
            ->groupBy('category_id')
            ->get()
            ->keyBy('category_id');

        $named = $project->featureCategories()->orderBy('name')->get(['id', 'name'])
            ->filter(fn ($category) => isset($rows[$category->id]))
            ->map(fn ($category) => ['id' => (string) $category->id, 'name' => $category->name, 'row' => $rows[$category->id]]);

        if (isset($rows[''])) {
            $named->push(['id' => 'none', 'name' => 'Uncategorised', 'row' => $rows['']]);
        }

        return $named->values()->map(fn ($item) => [
            'id' => $item['id'],
            'name' => $item['name'],
            'total' => (int) $item['row']->total,
            'implemented' => (int) $item['row']->implemented,
            'verified' => (int) $item['row']->verified,
        ]);
    }

    /**
     * Modules (feature categories) the coding agent was recently pointed at, newest first: features its
     * reports touched, and features sent to it with Proceed. Each module carries the latest request:
     * the report's `prompt` (what the agent was asked), else its summary, or the Proceed prompt's title.
     * Project-wide, like reports.
     *
     * @return list<array{id: string, name: string, last_at: Carbon, prompts: int, features: list<string>, latest: array{text: string, asked: bool, via: string, url: string}}>
     */
    public function recentModules(Project $project, int $days = 30, int $limit = 6): array
    {
        $since = now()->subDays($days);
        $events = collect();

        $reports = $project->developmentReports()->where('reported_at', '>=', $since)
            ->with('features:features.id,features.name,features.category_id')
            ->latest('reported_at')->latest('id')->limit(100)->get();
        foreach ($reports as $report) {
            foreach ($report->features as $feature) {
                $events->push([
                    'key' => "report-{$report->id}", 'category_id' => $feature->category_id, 'feature' => $feature->name, 'at' => $report->reported_at,
                    'latest' => ['text' => $report->prompt ?? $report->summary, 'asked' => $report->prompt !== null, 'via' => 'report', 'url' => route('reports.show', $report)],
                ]);
            }
        }

        // Proceed prompts a runner actually picked up. Only feature prompts point at one module.
        $runs = $project->agentRuns()->whereIn('kind', ['feature', 'diagrams'])->whereIn('status', [AgentRun::CLAIMED, AgentRun::LAUNCHED])
            ->where('created_at', '>=', $since)->latest()->latest('id')->limit(100)->get();
        $runFeatures = $project->features()->whereKey($runs->pluck('ref')->filter())->get(['id', 'name', 'category_id'])->keyBy('id');
        foreach ($runs as $run) {
            if ($feature = $runFeatures[$run->ref] ?? null) {
                $events->push([
                    'key' => "run-{$run->id}", 'category_id' => $feature->category_id, 'feature' => $feature->name, 'at' => $run->created_at,
                    'latest' => ['text' => $run->title, 'asked' => true, 'via' => 'proceed', 'url' => route('features.show', $feature)],
                ]);
            }
        }

        $names = $project->featureCategories()->pluck('name', 'id');

        return $events->groupBy(fn ($e) => (string) $e['category_id'])
            ->map(function (Collection $group, $categoryId) use ($names) {
                $categoryId = (string) $categoryId; // array keys come back as ints
                $group = $group->sortByDesc('at')->values();

                return [
                    'id' => $categoryId === '' ? 'none' : $categoryId,
                    'name' => $categoryId === '' ? 'Uncategorised' : $names[(int) $categoryId],
                    'last_at' => $group[0]['at'],
                    'prompts' => $group->pluck('key')->unique()->count(),
                    'features' => $group->pluck('feature')->unique()->values()->all(),
                    'latest' => $group[0]['latest'],
                ];
            })
            ->sortByDesc('last_at')->take($limit)->values()->all();
    }

    /**
     * Progress per project platform, in the project's platform order. A feature with no platform
     * counts on every platform, so the totals can add up to more than the feature count.
     * Ignores forPlatform(): this is the side-by-side view.
     *
     * @return list<array{platform: string, label: string, total: int, implemented: int, verified: int, needs_attention: int, only: int, progress: array}>
     */
    public function byPlatform(Project $project): array
    {
        return array_map(function (Platform $platform) use ($project) {
            $features = fn () => $project->features()->onPlatform($platform)->whereNot('status', FeatureStatus::Ignored);
            $total = $features()->count();
            $implemented = $features()->where('status', FeatureStatus::Implemented)->count();
            $verified = $features()->where('status', FeatureStatus::Implemented)->where('verification_status', VerificationStatus::Verified)->count();

            return [
                'platform' => $platform->value,
                'label' => $platform->label(),
                'total' => $total,
                'implemented' => $implemented,
                'verified' => $verified,
                'needs_attention' => self::scopeNeedsAttention($features()->getQuery())->count(),
                'only' => $project->features()->whereNot('status', FeatureStatus::Ignored)->whereJsonContains('platforms', $platform->value)->count(), // tagged with it
                'progress' => self::progress($implemented, $verified, $total),
            ];
        }, $project->platformList());
    }

    /**
     * Feature status changes per week (weeks start Monday), oldest first, including empty weeks.
     *
     * @return list<array{week: Carbon, count: int}>
     */
    public function weeklyActivity(Project $project, int $weeks = 12): array
    {
        $start = now()->startOfWeek()->subWeeks($weeks - 1);

        $counts = FeatureStatusChange::query()
            ->whereHas('feature', $this->featureScope($project))
            ->where('created_at', '>=', $start)
            ->pluck('created_at')
            ->countBy(fn ($at) => Carbon::parse($at)->startOfWeek()->toDateString());

        return collect(range(0, $weeks - 1))
            ->map(fn (int $i) => $start->copy()->addWeeks($i))
            ->map(fn (Carbon $week) => ['week' => $week, 'count' => $counts[$week->toDateString()] ?? 0])
            ->all();
    }

    /**
     * @param  list<\BackedEnum>  $cases
     * @return array<string, int>
     */
    private function countBy(Project $project, string $column, array $cases): array
    {
        $counts = $this->features($project)
            ->toBase()
            ->selectRaw("{$column} as value, count(*) as aggregate")
            ->groupBy($column)
            ->pluck('aggregate', 'value');

        return collect($cases)->mapWithKeys(fn ($case) => [$case->value => (int) ($counts[$case->value] ?? 0)])->all();
    }
}
