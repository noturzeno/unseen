<?php

namespace App\Http\Resources;

use App\Models\FeatureCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeatureCategory */
class FeatureCategoryResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->project_id,
            'name' => $this->name,
            'description' => $this->description,
            'position' => $this->position,
            'features_count' => $this->whenCounted('features'),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
