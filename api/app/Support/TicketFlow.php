<?php

namespace App\Support;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Where a ticket is on Open → In progress → QA → Client review → Done, for the ticket page's
 * tracker. Uses the same step states as FeatureLifecycle: done, current, upcoming, waiting
 * (with QA or the client), failed (QA failed), blocked (client asked for changes), stopped (closed).
 */
class TicketFlow
{
    private const STEPS = [
        ['key' => 'open', 'label' => 'Open', 'statuses' => ['open']],
        ['key' => 'build', 'label' => 'In progress', 'statuses' => ['in_progress']],
        ['key' => 'qa', 'label' => 'QA', 'statuses' => ['ready_for_qa', 'qa_failed']],
        ['key' => 'client', 'label' => 'Client review', 'statuses' => ['client_review', 'changes_requested']],
        ['key' => 'done', 'label' => 'Done', 'statuses' => ['done', 'closed']],
    ];

    /**
     * @param  Collection<int, TicketComment>  $thread  oldest first
     * @return list<array{key: string, label: string, state: string, at: ?Carbon, hint: ?string}>
     */
    public static function steps(Ticket $ticket, Collection $thread): array
    {
        [$position, $state, $hint] = match ($ticket->status) {
            TicketStatus::Open => [0, 'current', 'Not started'],
            TicketStatus::InProgress => [1, 'current', 'Being built'],
            TicketStatus::ReadyForQa => [2, 'waiting', 'Waiting for QA'],
            TicketStatus::QaFailed => [2, 'failed', 'QA failed'],
            TicketStatus::ClientReview => [3, 'waiting', 'Waiting for the client'],
            TicketStatus::ChangesRequested => [3, 'blocked', 'Client asked for changes'],
            TicketStatus::Done => [4, 'done', 'Signed off'],
            TicketStatus::Closed => [4, 'stopped', 'Closed without finishing'],
        };

        $steps = [];
        foreach (self::STEPS as $i => $step) {
            $stepState = match (true) {
                $i < $position => 'done',
                $i === $position => $state,
                default => 'upcoming',
            };
            // Latest time the ticket entered this step (QA can happen more than once).
            $at = $stepState === 'upcoming' ? null : $thread
                ->filter(fn (TicketComment $c) => $c->to_status && in_array($c->to_status->value, $step['statuses'], true))
                ->max('created_at');

            $steps[] = [
                'key' => $step['key'],
                'label' => $step['label'],
                'state' => $stepState,
                'at' => $at ? Carbon::parse($at) : ($i === 0 ? $ticket->created_at : null),
                'hint' => $i === $position ? $hint : null,
            ];
        }

        return $steps;
    }
}
