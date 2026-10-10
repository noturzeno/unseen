<?php

namespace App\Services;

use App\Enums\ClaimSource;
use App\Enums\CommentRole;
use App\Enums\FeatureStatus;
use App\Enums\TestOutcome;
use App\Enums\VerificationStatus;
use App\Models\DevelopmentReport;
use App\Models\FeatureEvidence;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\UnresolvedItem;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records a development report and applies its feature claims.
 *
 * Rules for each affected feature:
 *  - A report never sets verification to `verified`. Test results in a report are
 *    the reporter's claim, not verification.
 *  - A claimed implementation status is applied with the report's source, so the
 *    FeatureStatusService rules still apply (e.g. implemented -> needs_verification).
 *  - If the feature was `verified` or `failed`, it moves to `needs_verification`:
 *    the code changed after it was checked, so the old result no longer applies.
 *  - Every affected feature gets a feature_evidence row linking it to the report.
 *  - Each `unresolved` entry becomes an open UnresolvedItem, with who it waits on (client, team, agent) if given. `resolves` closes open items
 *    of this project, recorded with the report's source (the reporter's claim).
 *  - Each `tickets` entry adds a thread entry to that ticket linked to the report, moving it to the
 *    claimed status (e.g. ready_for_qa) with the note.
 */
class DevelopmentReportService
{
    public function __construct(
        private readonly FeatureStatusService $statuses,
        private readonly UnresolvedItemService $unresolved,
        private readonly TicketService $tickets,
    ) {}

    /**
     * @param  array{
     *     summary: string, prompt?: ?string, agent?: ?string, confidence?: ?string, limitations?: ?string,
     *     git_branch?: ?string, git_commit?: ?string, diff_ref?: ?string, reported_at?: mixed,
     *     changed_files?: list<array{path: string, change?: ?string}>,
     *     tests?: list<array{command: string, outcome: string, summary?: ?string}>,
     *     unresolved?: list<array{text: string, waiting_on?: ?string, decision_note?: ?string}>,
     *     features?: list<array{id: int, status?: ?string, note?: ?string}>,
     *     resolves?: list<int>,
     *     tickets?: list<array{id: int, status?: ?string, note?: ?string}>,
     * }  $data  Already validated; feature ids must belong to $project.
     */
    public function record(Project $project, array $data, ClaimSource $source, ?User $user): DevelopmentReport
    {
        return DB::transaction(function () use ($project, $data, $source, $user) {
            $tests = array_values($data['tests'] ?? []);

            $report = $project->developmentReports()->make([
                ...collect($data)->only(['agent', 'prompt', 'summary', 'confidence', 'limitations', 'git_branch', 'git_commit', 'diff_ref'])->all(),
                'changed_files' => array_values($data['changed_files'] ?? []),
                'tests' => $tests,
                'unresolved' => array_column($data['unresolved'] ?? [], 'text'),
                'reported_at' => $data['reported_at'] ?? now(),
            ]);
            $report->source = $source;
            $report->user_id = $user?->id;
            $report->tests_failed_count = collect($tests)
                ->whereIn('outcome', [TestOutcome::Failed->value, TestOutcome::Error->value])
                ->count();
            $report->save();

            foreach ($data['unresolved'] ?? [] as $entry) {
                $report->unresolvedItems()->create([
                    'project_id' => $project->id,
                    'text' => $entry['text'],
                    'waiting_on' => $entry['waiting_on'] ?? null,
                    'decision_note' => $entry['decision_note'] ?? null,
                ]);
            }

            $project->unresolvedItems()->whereKey($data['resolves'] ?? [])->where('status', UnresolvedItem::OPEN)
                ->get()->each(fn (UnresolvedItem $item) => $this->unresolved->resolve($item, $source, $user, "Resolved in report #{$report->id}.", $report));

            $claims = collect($data['features'] ?? [])->keyBy('id');
            $features = $project->features()->whereKey($claims->keys())->lockForUpdate()->get();

            foreach ($features as $feature) {
                $claim = $claims[$feature->id];
                $claimed = isset($claim['status']) ? FeatureStatus::from($claim['status']) : null;

                $report->evidence()->create([
                    'feature_id' => $feature->id,
                    'kind' => FeatureEvidence::KIND_DEVELOPMENT_REPORT,
                    'source' => $source,
                    'claimed_status' => $claimed,
                    'note' => $claim['note'] ?? null,
                ]);

                $newStatus = $claimed !== null && $claimed !== $feature->status ? $claimed : null;
                $resetVerification = in_array($feature->verification_status, [VerificationStatus::Verified, VerificationStatus::Failed], true)
                    ? VerificationStatus::NeedsVerification
                    : null;

                if ($newStatus || $resetVerification) {
                    $this->statuses->apply(
                        $feature,
                        $newStatus,
                        $resetVerification,
                        $source,
                        $user,
                        $resetVerification
                            ? "Changed in report #{$report->id} after it was last checked."
                            : "From report #{$report->id}.",
                    );
                } else {
                    $feature->touch();
                }
            }

            $ticketClaims = collect($data['tickets'] ?? [])->keyBy('id');
            $project->tickets()->whereKey($ticketClaims->keys())->orderBy('id')->get()
                ->each(fn (Ticket $ticket) => $this->tickets->comment($ticket, [
                    'author_role' => CommentRole::Agent->value,
                    'body' => $ticketClaims[$ticket->id]['note'] ?? null,
                    'status' => $ticketClaims[$ticket->id]['status'] ?? null,
                ], $source, $user, $report));

            return $report;
        });
    }
}
