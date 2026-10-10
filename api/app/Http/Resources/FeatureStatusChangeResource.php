<?php

namespace App\Http\Resources;

use App\Models\FeatureStatusChange;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeatureStatusChange */
class FeatureStatusChangeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'feature' => $this->whenLoaded('feature', fn () => ['id' => $this->feature->id, 'name' => $this->feature->name]),
            'field' => $this->field,
            'from' => $this->from_value,
            'to' => $this->to_value,
            'source' => $this->source,
            'user' => $this->whenLoaded('user', fn () => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null),
            'note' => $this->note,
            'created_at' => $this->created_at,
        ];
    }
}
