<?php

namespace App\Http\Resources;

use App\Models\RepositoryScan;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin RepositoryScan */
class RepositoryScanResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'scanner' => $this->scanner,
            'git' => ['branch' => $this->git_branch, 'commit' => $this->git_commit],
            'candidates' => $this->candidates_count,
            'new' => $this->new_count,
            'updated' => $this->updated_count,
            'pending_review' => $this->whenHas('pending_review'),
            'submitted_by' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'created_at' => $this->created_at,
        ];
    }
}
