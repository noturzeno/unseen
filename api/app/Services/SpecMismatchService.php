<?php

namespace App\Services;

use App\Enums\ClaimSource;
use App\Enums\MismatchDecision;
use App\Enums\MismatchStatus;
use App\Models\Project;
use App\Models\SpecMismatch;
use App\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * PRD vs design mismatches. Rules:
 *  1. The coding agent records them. With a `key`, recording again updates the same row, so rescans
 *     don't duplicate. Re-recording never touches a decision.
 *  2. Only people decide (web UI, or a token with `write`): follow the PRD, keep the design, or other.
 *  3. The agent marks a decided mismatch applied once the code (or the PRD) matches the decision.
 *     An undecided one can't be applied: the agent must not pick a side.
 */
class SpecMismatchService
{
    public const DETAILS = ['key', 'reference', 'title', 'prd_says', 'design_says', 'built_to', 'built_note', 'impact', 'decide_by', 'feature_id'];

    /**
     * @param  array<string, mixed>  $data  validated details
     * @return array{0: SpecMismatch, 1: bool} the mismatch and whether it was new
     */
    public function record(Project $project, array $data, ClaimSource $source, ?User $user): array
    {
        $details = collect($data)->only(self::DETAILS)->all();
        $existing = isset($details['key']) ? $project->specMismatches()->where('key', $details['key'])->first() : null;

        if ($existing) {
            $existing->update($details);

            return [$existing, false];
        }

        return [$project->specMismatches()->create([...$details, 'source' => $source, 'user_id' => $user?->id]), true];
    }

    public function decide(SpecMismatch $mismatch, MismatchDecision $decision, ?string $note, User $user): void
    {
        if ($mismatch->status === MismatchStatus::Applied) {
            throw ValidationException::withMessages(['decision' => 'This mismatch was already applied. Record a new one if the decision changes.']);
        }
        if ($decision === MismatchDecision::Other && blank($note)) {
            throw ValidationException::withMessages(['decision_note' => 'Say what to do instead.']);
        }

        $mismatch->update([
            'status' => MismatchStatus::Decided,
            'decision' => $decision,
            'decision_note' => filled($note) ? $note : null,
            'decided_by' => $user->id,
            'decided_at' => now(),
        ]);
    }

    /** Take a decision back while it hasn't been applied. */
    public function reopen(SpecMismatch $mismatch): void
    {
        if ($mismatch->status !== MismatchStatus::Decided) {
            throw ValidationException::withMessages(['status' => 'Only a decided, not yet applied, mismatch can be reopened.']);
        }

        $mismatch->update(['status' => MismatchStatus::Open, 'decision' => null, 'decision_note' => null, 'decided_by' => null, 'decided_at' => null]);
    }

    public function applied(SpecMismatch $mismatch, ?string $note): void
    {
        if ($mismatch->status !== MismatchStatus::Decided) {
            throw ValidationException::withMessages(['status' => $mismatch->status === MismatchStatus::Open
                ? 'Nobody has decided this mismatch yet. Don\'t pick a side: leave it for the person who decides.'
                : 'Already applied.']);
        }

        $mismatch->update(['status' => MismatchStatus::Applied, 'applied_note' => $note, 'applied_at' => now()]);
    }

    /**
     * @return array{open: int, team: int, client: int, decided: int, applied: int}
     */
    public function counts(Project $project): array
    {
        $rows = $project->specMismatches()->toBase()->selectRaw('status, decide_by, count(*) as aggregate')->groupBy('status', 'decide_by')->get();
        $sum = fn (callable $filter) => (int) $rows->filter($filter)->sum('aggregate');

        return [
            'open' => $sum(fn ($r) => $r->status === 'open'),
            'team' => $sum(fn ($r) => $r->status === 'open' && $r->decide_by === 'team'),
            'client' => $sum(fn ($r) => $r->status === 'open' && $r->decide_by === 'client'),
            'decided' => $sum(fn ($r) => $r->status === 'decided'),
            'applied' => $sum(fn ($r) => $r->status === 'applied'),
        ];
    }
}
