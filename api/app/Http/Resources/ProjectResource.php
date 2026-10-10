<?php

namespace App\Http\Resources;

use App\Models\Project;
use App\Services\ProjectDashboard;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Project */
class ProjectResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'description' => $this->description,
            'repository_url' => $this->repository_url,
            'tech_stack' => $this->tech_stack,
            'owner' => new UserResource($this->whenLoaded('owner')),
            'is_owner' => $request->user() && $this->isOwnedBy($request->user()),
            'archived' => $this->isArchived(),
            'summary' => $this->when(isset($this->features_count), fn () => [
                'total' => $this->features_count,
                'implemented' => $this->implemented_features_count,
                'implemented_and_verified' => $this->verified_features_count,
                'needs_attention' => $this->attention_features_count,
                'progress' => ProjectDashboard::progress($this->implemented_features_count, $this->verified_features_count, $this->features_count),
            ]),
            'last_activity_at' => $this->when(isset($this->features_count), fn () => $this->lastActivityAt()),
            'archived_at' => $this->archived_at,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
