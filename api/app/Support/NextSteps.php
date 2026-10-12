<?php

namespace App\Support;

use App\Enums\FeatureStatus;
use App\Enums\PhaseStatus;
use App\Enums\SuggestionStatus;
use App\Enums\VerificationStatus;
use App\Models\FeatureCheck;
use App\Models\Project;
use App\Models\UnresolvedItem;
use App\Services\RoadmapService;
use Illuminate\Support\Collection;

/**
 * Suggested next steps for a project, ranked by fixed rules (broken before blocked before new work),
 * each with a reason and a prompt for the project's coding agent. Read-only: it only suggests.
 */
final class NextSteps
{
    public const HIGH = 1;

    public const MEDIUM = 2;

    public const LOW = 3;

    /** Words that mark an unresolved item as a defect or a security/privacy problem. */
    private const DEFECT = '/\b(defect|bug|broken|crash|error|errors|500|fail(s|ing|ed)?|exception|security|privacy|leak(s|ed)?|vulnerab\w*|xss|csrf|injection|regression)\b/i';

    /**
     * @return Collection<int, array{priority: int, kind: string, title: string, reason: string, url: string, prompt: string}>
     */
    public static function for(Project $project): Collection
    {
        $url = rtrim((string) config('app.url'), '/');
        $steps = collect();
        $add = function (int $priority, string $kind, string $title, string $reason, string $link, string $prompt, string $run, ?int $ref = null) use ($steps) {
            $steps->push(compact('priority', 'kind', 'title', 'reason', 'prompt', 'run', 'ref') + ['url' => $link]);
        };

        $features = fn () => $project->features()->with(['project', 'category', 'acceptanceCriteria', 'checks.latestResult']);

        // Broken: verification failed.
        foreach ($features()->where('verification_status', VerificationStatus::Failed)->whereNot('status', FeatureStatus::Ignored)->orderBy('updated_at')->limit(3)->get() as $f) {
            $add(self::HIGH, 'fix', "Fix “{$f->name}”", 'Verification failed: its tests show it is broken.', route('features.show', $f), FeaturePrompt::build($f)['prompt'], 'feature', $f->id);
        }

        // Known defects and security/privacy items in open unresolved work.
        $open = $project->unresolvedItems()->with('report:id,summary')->where('status', UnresolvedItem::OPEN)->latest()->latest('id')->get();
        $open->each(fn ($i) => $i->setRelation('project', $project));
        // Items waiting on a decision from you or the client aren't handed to the agent.
        $waiting = $open->filter(fn ($i) => $i->needsDecision());
        $open = $open->reject(fn ($i) => $i->needsDecision())->values();
        $defects = $open->filter(fn ($i) => preg_match(self::DEFECT, $i->text))->take(3);
        foreach ($defects as $item) {
            $add(self::HIGH, 'defect', 'Resolve: '.self::short($item->text), 'Open item that looks like a defect or a security/privacy problem.', route('unresolved.index', $project), UnresolvedPrompt::forItem($item), 'item', $item->id);
        }

        // PRD vs design decisions people made, waiting for the agent to carry them out.
        if ($project->mismatches_enabled && $apply = MismatchPrompt::apply($project)) {
            $n = $project->specMismatches()->where('status', 'decided')->count();
            $add(self::HIGH, 'mismatches', "Apply {$n} PRD vs design ".str('decision')->plural($n), 'You decided these; the code or the PRD still has to change to match.', route('mismatches.index', $project), $apply, 'apply_mismatches');
        }

        // Roadmap: the current phase (high when overdue).
        $phases = app(RoadmapService::class)->phases($project);
        $phases->each(fn ($p) => $p->setRelation('project', $project));
        $current = $phases->first(fn ($p) => $p->status !== PhaseStatus::Done);
        $overdue = $current && $current->target_date && $current->target_date->isPast();
        if ($current) {
            $next = PhasePrompt::build($current);
            $progress = RoadmapService::progress($current);
            $add(
                $overdue ? self::HIGH : self::MEDIUM, 'phase',
                "{$next['label']}: {$current->key} {$current->name}",
                ($overdue ? 'Overdue since '.$current->target_date->format('j M Y').'. ' : 'The current roadmap phase. ')
                    .($progress['items'] ? "{$progress['done']} of {$progress['items']} items done." : ''),
                route('roadmap.index', $project), $next['prompt'], 'phase', $current->id,
            );
        }

        // Blocked features.
        foreach ($features()->where('status', FeatureStatus::Blocked)->orderBy('updated_at')->limit(3)->get() as $f) {
            $add(self::MEDIUM, 'unblock', "Unblock “{$f->name}”", 'Blocked: nothing moves until the blocker is cleared.', route('features.show', $f), FeaturePrompt::build($f)['prompt'], 'feature', $f->id);
        }

        // Scan suggestions waiting for review.
        $pending = $project->featureSuggestions()->where('status', SuggestionStatus::Pending)->count();
        if ($pending) {
            $add(self::MEDIUM, 'suggestions', "Review {$pending} scan ".str('suggestion')->plural($pending), 'Features found in the code or docs that aren\'t recorded yet.', route('suggestions.index', $project),
                self::prompt($project, 'suggestions'), 'suggestions');
        }

        // Verification: tests linked but never run in CI; implemented features without tests.
        $hasChecks = FeatureCheck::whereIn('feature_id', $project->features()->select('id'))->exists();
        if ($hasChecks && ! $project->testRuns()->exists()) {
            $add(self::MEDIUM, 'ci', 'Set up CI verification', 'Tests are linked to features, but no test run was ever sent, so nothing can be verified.', route('projects.show', $project),
                self::prompt($project, 'ci'), 'ci');
        }
        $untested = $project->features()->where('status', FeatureStatus::Implemented)->doesntHave('checks')->orderBy('name')->get(['id', 'name']);
        if ($untested->isNotEmpty()) {
            $add(self::MEDIUM, 'tests', "Link tests to {$untested->count()} implemented ".str('feature')->plural($untested->count()), 'Without linked tests they can never be verified.', route('projects.show', $project),
                self::prompt($project, 'tests'), 'tests');
        }

        // Several platforms, but no feature says which it is on: every platform shows the same numbers.
        if ($project->isMultiPlatform() && $project->features()->exists() && ! $project->features()->whereNotNull('platforms')->exists()) {
            $add(self::MEDIUM, 'platforms', 'Tag features with their platforms', 'No feature is tagged with a platform yet, so every platform shows the same progress.', route('projects.show', $project),
                self::prompt($project, 'platforms'), 'platforms');
        }

        // Work already started, oldest first.
        foreach ($features()->where('status', FeatureStatus::InProgress)->orderBy('updated_at')->limit(3)->get() as $f) {
            $add(self::LOW, 'continue', "Continue “{$f->name}”", 'In progress; last updated '.$f->updated_at->diffForHumans().'.', route('features.show', $f), FeaturePrompt::build($f)['prompt'], 'feature', $f->id);
        }

        // The rest of the open items.
        $rest = $open->reject(fn ($i) => $defects->contains($i));
        if ($open->whereNull('waiting_on')->count() >= 2) {
            $add(self::MEDIUM, 'sort', 'Find out which open items need your or the client\'s decision', $open->whereNull('waiting_on')->count().' open items are not sorted by who has to act.',
                route('unresolved.index', $project), UnresolvedPrompt::forSorting($project, $open->merge($waiting), $open->count() + $waiting->count()), 'sort');
        }
        if ($rest->isNotEmpty()) {
            $add(self::LOW, 'unresolved', "Work through {$rest->count()} open ".str('item')->plural($rest->count()), 'Unfinished work earlier reports left open.', route('unresolved.index', $project),
                UnresolvedPrompt::forAll($project, $open->merge($waiting), $open->count() + $waiting->count()), 'items');
        }

        return $steps->sortBy('priority')->values();
    }

    /**
     * The project-wide prompts: review scan suggestions, set up CI verification, link tests.
     */
    public static function prompt(Project $project, string $kind): string
    {
        $url = rtrim((string) config('app.url'), '/');

        return match ($kind) {
            'suggestions' => self::render('suggestions', $project, $url, ['pending' => $project->featureSuggestions()->where('status', SuggestionStatus::Pending)->count()]),
            'ci' => self::render('ci', $project, $url),
            'tests' => self::render('tests', $project, $url, ['features' => $project->features()
                ->where('status', FeatureStatus::Implemented)->doesntHave('checks')->orderBy('name')->get(['id', 'name'])]),
            'platforms' => self::render('platforms', $project, $url, [
                'platforms' => collect($project->platformList())->map(fn ($p) => $p->value)->join(', '),
                'untagged' => $project->features()->whereNull('platforms')->count(),
                'total' => $project->features()->count(),
            ]),
        };
    }

    private static function render(string $kind, Project $project, string $url, array $data = []): string
    {
        $text = view('agent.next-step-prompt', $data + [
            'kind' => $kind,
            'project' => trim(preg_replace('/\s+/', ' ', str_replace('"', "'", $project->name))),
            'projectId' => $project->id,
            'url' => $url,
        ])->render();

        return trim(preg_replace("/\n{3,}/", "\n\n", $text))."\n";
    }

    private static function short(string $text): string
    {
        $one = trim(preg_replace('/\s+/', ' ', $text));

        return mb_strlen($one) > 80 ? rtrim(mb_substr($one, 0, 79)).'…' : $one;
    }
}
