<?php

namespace App\Services;

use App\Enums\ClaimSource;
use App\Models\DevelopmentReport;
use App\Models\UnresolvedItem;
use App\Models\User;

/**
 * Closing and reopening unresolved work. Each closure records who or what closed it,
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
