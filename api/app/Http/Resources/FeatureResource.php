<?php

namespace App\Http\Resources;

use App\Models\Feature;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin Feature */
class FeatureResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'category' => $this->whenLoaded('category', fn () => $this->category
                ? ['id' => $this->category->id, 'name' => $this->category->name]
                : null),
            'name' => $this->name,
            'description' => $this->description,
            'status' => $this->status,
            'verification_status' => $this->verification_status,
            'acceptance_criteria' => $this->whenLoaded('acceptanceCriteria', fn () => $this->acceptanceCriteria->map(fn ($c) => [
                'id' => $c->id,
                'description' => $c->description,
                'position' => $c->position,
                'source' => $c->source,
            ])),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
