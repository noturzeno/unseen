<?php

namespace App\Enums;

/**
 * Where a ticket is in the agency flow: build → QA → client review → done.
 * Failed QA and client change requests send it back to development. Recorded by the coding
 * agent from what QA and the client said; "done" is a claim, not verification.
 */
enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case ReadyForQa = 'ready_for_qa';
    case QaFailed = 'qa_failed';
    case ClientReview = 'client_review';
    case ChangesRequested = 'changes_requested';
    case Done = 'done';
    case Closed = 'closed';

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In progress',
            self::ReadyForQa => 'Ready for QA',
            self::QaFailed => 'QA failed',
            self::ClientReview => 'Client review',
            self::ChangesRequested => 'Changes requested',
            self::Done => 'Done',
            self::Closed => 'Closed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Open, self::Closed => '',
            self::InProgress, self::ReadyForQa, self::ClientReview => 'info',
            self::QaFailed => 'bad',
            self::ChangesRequested => 'warn',
            self::Done => 'ok',
        };
    }

    /** Still needs work or sign-off. */
    public function isOpen(): bool
    {
        return ! in_array($this, [self::Done, self::Closed], true);
    }

    /** Sent back by QA or the client: development has to act on the feedback. */
    public function isSentBack(): bool
    {
        return in_array($this, [self::QaFailed, self::ChangesRequested], true);
    }

    /** @return list<self> */
    public static function open(): array
    {
        return array_values(array_filter(self::cases(), fn (self $s) => $s->isOpen()));
    }
}
