<?php

namespace App\Services;

use App\Enums\ClaimSource;
use App\Enums\CommentRole;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\DevelopmentReport;
use App\Models\Project;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Records tickets and their thread. Every status change becomes a thread entry with who it is
 * attributed to (QA, client, agent…), so the flow build → QA → client review → done can be
 * followed afterwards. Comments are stored as given: callers must keep out personal data and secrets.
 */
class TicketService
{
    /**
     * @param  array{title: string, reference?: ?string, description?: ?string, type?: ?string, status?: ?string,
     *     priority?: ?string, raised_by?: ?string, feature_id?: ?int, comment?: ?string, author_name?: ?string}  $data
     */
    public function create(Project $project, array $data, ClaimSource $source, ?User $user): Ticket
    {
        return DB::transaction(function () use ($project, $data, $source, $user) {
            $status = TicketStatus::tryFrom($data['status'] ?? '') ?? TicketStatus::Open;

            $ticket = $project->tickets()->create([
                ...collect($data)->only(['reference', 'title', 'description', 'type', 'priority', 'raised_by', 'feature_id'])->filter(fn ($v) => $v !== null)->all(),
                'type' => TicketType::tryFrom($data['type'] ?? '') ?? TicketType::Task,
                'status' => $status,
                'user_id' => $user?->id,
                'source' => $source,
                'closed_at' => $status->isOpen() ? null : now(),
            ]);

            // The opening entry: the ticket's first words, from whoever raised it.
            $this->entry($ticket, [
                'author_role' => $data['raised_by'] ?? CommentRole::Agent->value,
                'author_name' => $data['author_name'] ?? null,
                'body' => $data['comment'] ?? null,
                'from_status' => null,
                'to_status' => $status,
            ], $source, $user);

            return $ticket;
        });
    }

    /**
     * Edit details and/or move the ticket. A status change is logged with `note` and `author_role`
     * (default: the agent itself).
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Ticket $ticket, array $data, ClaimSource $source, ?User $user, ?DevelopmentReport $report = null): Ticket
    {
        return DB::transaction(function () use ($ticket, $data, $source, $user, $report) {
            $ticket->fill(collect($data)->only(['reference', 'title', 'description', 'type', 'priority', 'raised_by', 'feature_id'])->all());
            $ticket->save();

            if (array_key_exists('status', $data) || isset($data['note'])) {
                $this->comment($ticket, [
                    'author_role' => $data['author_role'] ?? CommentRole::Agent->value,
                    'author_name' => $data['author_name'] ?? null,
                    'body' => $data['note'] ?? null,
                    'status' => $data['status'] ?? null,
                ], $source, $user, $report);
            }

            return $ticket;
        });
    }

    /**
     * Add to the thread. With `status`, the comment also moves the ticket (e.g. QA fails it, giving the reason).
     *
     * @param  array{author_role: string, author_name?: ?string, body?: ?string, status?: ?string}  $data
     */
    public function comment(Ticket $ticket, array $data, ClaimSource $source, ?User $user, ?DevelopmentReport $report = null): ?TicketComment
    {
        return DB::transaction(function () use ($ticket, $data, $source, $user, $report) {
            $to = TicketStatus::tryFrom($data['status'] ?? '');
            $changes = $to !== null && $to !== $ticket->status;
            $body = isset($data['body']) && trim($data['body']) !== '' ? $data['body'] : null;

            if (! $changes && $body === null && $report === null) {
                return null; // nothing to record
            }

            $from = $ticket->status;
            if ($changes) {
                $ticket->status = $to;
                $ticket->closed_at = $to->isOpen() ? null : now();
            }
            $ticket->touch();

            return $this->entry($ticket, [
                'author_role' => $data['author_role'],
                'author_name' => $data['author_name'] ?? null,
                'body' => $body,
                'from_status' => $changes ? $from : null,
                'to_status' => $changes ? $to : null,
                'development_report_id' => $report?->id,
            ], $source, $user);
        });
    }

    private function entry(Ticket $ticket, array $attributes, ClaimSource $source, ?User $user): TicketComment
    {
        return $ticket->comments()->create([
            ...$attributes,
            'project_id' => $ticket->project_id,
            'user_id' => $user?->id,
            'source' => $source,
        ]);
    }
}
