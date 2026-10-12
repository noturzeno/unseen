<?php

namespace App\Services;

use App\Enums\ClaimSource;
use App\Enums\WaitingOn;
use App\Models\DevelopmentReport;
use App\Models\UnresolvedItem;
use App\Models\User;

/**
 * Deciding, closing, ignoring and reopening unresolved work. Each closure records who or what closed it,
 * since an agent saying "fixed" is a claim, just like any other report content.
 */
class UnresolvedItemService
{
    public function resolve(UnresolvedItem $item, ClaimSource $source, ?User $user, ?string $note = null, ?DevelopmentReport $report = null): void
    {
        if (! $item->isOpen()) {
            return;
        }

        $item->update([
            'status' => UnresolvedItem::RESOLVED,
            'resolved_at' => now(),
            'resolved_by' => $user?->id,
            'resolved_source' => $source,
            'resolved_report_id' => $report?->id,
            'resolution_note' => $note,
        ]);
    }

    /**
     * A person's answer to an item that waited on them or the client. The item then waits on the
     * agent: its prompt carries the decision, so the agent can carry it out.
     */
    public function decide(UnresolvedItem $item, string $decision, User $user): void
    {
        $item->update([
            'waiting_on' => WaitingOn::Agent,
            'decision' => $decision,
            'decided_by' => $user->id,
            'decided_at' => now(),
        ]);
    }

    /**
     * A person closes an open item without it being done. Reports can't resolve it afterwards
     * (resolve() only closes open items); reopen() brings it back.
     */
    public function ignore(UnresolvedItem $item, string $reason, User $user): void
    {
        if (! $item->isOpen()) {
            return;
        }

        $item->update([
            'status' => UnresolvedItem::IGNORED,
            'resolved_at' => now(),
            'resolved_by' => $user->id,
            'resolved_source' => ClaimSource::UserEntered,
            'resolved_report_id' => null,
            'resolution_note' => $reason,
        ]);
    }

    public function reopen(UnresolvedItem $item): void
    {
        $item->update([
            'status' => UnresolvedItem::OPEN,
            'resolved_at' => null,
            'resolved_by' => null,
            'resolved_source' => null,
            'resolved_report_id' => null,
            'resolution_note' => null,
        ]);
    }
}
