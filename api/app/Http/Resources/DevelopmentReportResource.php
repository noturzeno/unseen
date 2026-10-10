<?php

namespace App\Http\Resources;

use App\Models\DevelopmentReport;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin DevelopmentReport */
class DevelopmentReportResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'source' => $this->source,
            'agent' => $this->agent,
            'submitted_by' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'summary' => $this->summary,
            'confidence' => $this->confidence,
            'limitations' => $this->limitations,
            'git' => [
                'branch' => $this->git_branch,
                'commit' => $this->git_commit,
                'diff_ref' => $this->diff_ref,
            ],
            'features' => $this->whenLoaded('features', fn () => $this->features->map(fn ($f) => [
                'id' => $f->id,
                'name' => $f->name,
                'claimed_status' => $f->pivot->claimed_status,
                'note' => $f->pivot->note,
            ])),
            'changed_files' => $this->changed_files ?? [],
            'tests' => $this->tests ?? [],
            'test_counts' => $this->testCounts(),
            'unresolved' => $this->unresolved ?? [],
            'tickets' => $this->whenLoaded('ticketUpdates', fn () => $this->ticketUpdates->map(fn ($u) => [
                'id' => $u->ticket_id,
                'label' => $u->ticket->label(),
                'title' => $u->ticket->title,
                'from_status' => $u->from_status,
                'to_status' => $u->to_status,
                'note' => $u->body,
            ])),
            'reported_at' => $this->reported_at,
            'created_at' => $this->created_at,
        ];
    }
}
