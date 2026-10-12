<?php

namespace App\Livewire;

use App\Enums\FeatureStatus;
use App\Enums\Platform;
use App\Enums\VerificationStatus;
use App\Models\Project;
use App\Services\ProjectDashboard;
use App\Services\RoadmapService;
use App\Support\Cli;
use App\Support\NextSteps;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

/**
 * Read-only project dashboard. Data comes from coding agents through the API.
 */
class ProjectOverview extends Component
{
    use WithPagination;

    public Project $project;

    #[Url]
    public string $status = '';

    #[Url]
    public string $verification = '';

    #[Url(as: 'q')]
    public string $search = '';

    /**
     * One of the project's platforms, or "" for all. Narrows the feature numbers, charts and lists;
     * kept when filters are cleared because it is the view, not a filter.
     */
    #[Url]
    public string $platform = '';

    /** Category id, "none" for uncategorised, or "" for all. */
    #[Url]
    public string $category = '';

    public function mount(Project $project): void
    {
        Gate::authorize('view', $project);
    }

    public function updating($property): void
    {
        if (in_array($property, ['status', 'verification', 'category', 'search', 'platform'], true)) {
            $this->resetPage();
        }
    }

    /** Filter value: features that aren't implemented yet (any status but implemented). */
    public const UNBUILT = 'unbuilt';

    /** Filter value: features that aren't verified (any verification but verified). */
    public const UNVERIFIED = 'unverified';

    /**
     * Clicking a chart (a category bar or a status segment) filters the feature list and scrolls to it.
     * Values that don't belong to this project or aren't known filters are dropped, not trusted.
     */
    public function filterFeatures(string $category = '', string $status = '', string $verification = ''): void
    {
        $validCategory = $category === 'none'
            || (ctype_digit($category) && $this->project->featureCategories()->whereKey((int) $category)->exists());

        $this->category = $validCategory ? $category : '';
        $this->status = FeatureStatus::tryFrom($status) || $status === self::UNBUILT ? $status : '';
        $this->verification = VerificationStatus::tryFrom($verification) || $verification === self::UNVERIFIED ? $verification : '';
        $this->search = '';
        $this->resetPage();
        $this->dispatch('features-filtered'); // the Features card scrolls itself into view
    }

    /** Platform tabs and tiles. Platforms the project doesn't have fall back to all. */
    public function showPlatform(string $platform = ''): void
    {
        $this->platform = $platform;
        $this->resetPage();
    }

    /** The selected platform, if it is one of the project's. Unknown values from the URL are ignored. */
    private function selectedPlatform(): ?Platform
    {
        $platform = Platform::tryFrom($this->platform);

        return in_array($platform, $this->project->platformList(), true) ? $platform : null;
    }

    public function clearFilters(): void
    {
        $this->reset('status', 'verification', 'category', 'search');
        $this->resetPage();
    }

    /** "Admin panel · implemented · not verified", for the line above the feature list. */
    private function describeFilter(?FeatureStatus $status, ?VerificationStatus $verification): ?string
    {
        $parts = array_filter([
            match (true) {
                $this->category === 'none' => 'Uncategorised',
                ctype_digit($this->category) => $this->project->featureCategories()->whereKey((int) $this->category)->value('name'),
                default => null,
            },
            $status?->label() ?? ($this->status === self::UNBUILT ? 'Not implemented yet' : null),
            $verification?->label() ?? ($this->verification === self::UNVERIFIED ? 'Not verified' : null),
        ]);

        return $parts ? implode(' · ', $parts) : null;
    }

    /**
     * Chart segments as [label, count, tone, click]: clicking one filters the feature list.
     *
     * @return array{status: list<array>, verification: list<array>}
     */
    private static function chartSegments(array $summary): array
    {
        $bs = $summary['by_status'];
        $vs = $summary['by_verification_status'];

        return [
            'status' => [
                ['Implemented', $bs['implemented'], 'ok', "filterFeatures('', 'implemented')"],
                ['In progress', $bs['in_progress'], 'info', "filterFeatures('', 'in_progress')"],
                ['Planned', $bs['planned'], 'none', "filterFeatures('', 'planned')"],
                ['Blocked', $bs['blocked'], 'warn', "filterFeatures('', 'blocked')"],
                ['Not implemented', $bs['not_implemented'], 'none-2', "filterFeatures('', 'not_implemented')"],
            ],
            'verification' => [
                ['Verified', $vs['verified'], 'ok', "filterFeatures('', '', 'verified')"],
                ['Needs verification', $vs['needs_verification'], 'info', "filterFeatures('', '', 'needs_verification')"],
                ['Not checked', $vs['not_checked'], 'none', "filterFeatures('', '', 'not_checked')"],
                ['Failed', $vs['failed'], 'bad', "filterFeatures('', '', 'failed')"],
            ],
        ];
    }

    public function render(ProjectDashboard $dashboard, RoadmapService $roadmap)
    {
        Gate::authorize('view', $this->project);

        $platform = $this->selectedPlatform();
        $dashboard = $dashboard->forPlatform($platform);

        $summary = $dashboard->summary($this->project);
        $byCategory = $dashboard->byCategory($this->project)->map(fn (array $cat) => $cat + ['segments' => [
            ['Verified', $cat['verified'], 'ok', "filterFeatures('{$cat['id']}', '', 'verified')"],
            ['Implemented, not verified', $cat['implemented'] - $cat['verified'], 'info', "filterFeatures('{$cat['id']}', 'implemented', 'unverified')"],
            ['Not implemented yet', $cat['total'] - $cat['implemented'], 'none', "filterFeatures('{$cat['id']}', 'unbuilt')"],
        ]]);

        // Unknown filter values from the URL are ignored rather than trusted.
        $status = FeatureStatus::tryFrom($this->status);
        $verification = VerificationStatus::tryFrom($this->verification);

        $search = trim($this->search);

        $features = $this->project->features()
            ->with('category')
            ->when($platform, fn ($q, Platform $p) => $q->onPlatform($p))
            ->withCount('checks')
            ->when($search !== '', fn ($q) => $q->whereRaw("lower(name) like ? escape '\\'", ['%'.mb_strtolower(addcslashes($search, '%_\\')).'%']))
            ->when($status, fn ($q) => $q->where('status', $status))
            // Ignored features (decided not to build) only show when asked for.
            ->when($status !== FeatureStatus::Ignored, fn ($q) => $q->whereNot('status', FeatureStatus::Ignored))
            ->when($this->status === self::UNBUILT, fn ($q) => $q->where('status', '!=', FeatureStatus::Implemented))
            ->when($verification, fn ($q) => $q->where('verification_status', $verification))
            ->when($this->verification === self::UNVERIFIED, fn ($q) => $q->where('verification_status', '!=', VerificationStatus::Verified))
            ->when($this->category === 'none', fn ($q) => $q->whereNull('category_id'))
            ->when(ctype_digit($this->category), fn ($q) => $q->where('category_id', (int) $this->category))
            ->orderBy('name')
            ->paginate(25);

        return view('livewire.project-overview', [
            'summary' => $summary,
            'platforms' => $this->project->platformList(),
            'activePlatform' => $platform,
            'untaggedFeatures' => count($this->project->platformList()) > 1 ? $this->project->features()->whereNull('platforms')->count() : 0,
            'attention' => $dashboard->attention($this->project),
            'recentChanges' => $dashboard->recentChanges($this->project),
            'recentReports' => $dashboard->recentReports($this->project),
            'recentModules' => $dashboard->recentModules($this->project),
            'unresolved' => $dashboard->unresolved($this->project),
            'mismatches' => $this->project->mismatches_enabled ? $this->project->specMismatches()->with('feature:id,name')->where('status', 'open')
                ->orderByRaw("case decide_by when 'team' then 0 else 1 end")->latest('updated_at')->limit(3)->get() : collect(),
            'openTickets' => $dashboard->openTickets($this->project, 5),
            'feedback' => $dashboard->recentFeedback($this->project, 5),
            'withoutChecks' => $dashboard->withoutChecks($this->project),
            'byCategory' => $byCategory,
            'charts' => self::chartSegments($summary),
            'roadmap' => $roadmap->phases($this->project),
            'nextSteps' => NextSteps::for($this->project),
            'cliOutdated' => Cli::outdated($this->project, auth()->user()),
            'activity' => $dashboard->weeklyActivity($this->project),
            'categories' => $this->project->featureCategories()->withCount('features')->get(),
            'features' => $features,
            'filtered' => $status || $verification || $this->status === self::UNBUILT || $this->verification === self::UNVERIFIED
                || $this->category !== '' || $search !== '',
            'showing' => $this->describeFilter($status, $verification),
        ])->title($this->project->name);
    }
}
