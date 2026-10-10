<?php

namespace App\Http\Resources;

use App\Models\FeatureSuggestion;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeatureSuggestion */
class FeatureSuggestionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'key' => $this->key,
            'name' => $this->name,
            'description' => $this->description,
            'suggested_category' => $this->suggested_category,
            'suggested_status' => $this->suggested_status,
            'status' => $this->status,
            'source' => 'repository_detected',
            'feature' => $this->whenLoaded('feature', fn () => $this->feature ? ['id' => $this->feature->id, 'name' => $this->feature->name] : null),
            'matched_feature' => $this->whenLoaded('matchedFeature', fn () => $this->matchedFeature ? ['id' => $this->matchedFeature->id, 'name' => $this->matchedFeature->name] : null),
            'evidence' => $this->evidence,
            'first_scan_id' => $this->first_scan_id,
            'last_scan_id' => $this->last_scan_id,
            'reviewed_at' => $this->reviewed_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
