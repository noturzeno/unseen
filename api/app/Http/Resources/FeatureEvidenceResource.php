<?php

namespace App\Http\Resources;

use App\Models\FeatureEvidence;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin FeatureEvidence */
class FeatureEvidenceResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'kind' => $this->kind,
            'source' => $this->source,
            'claimed_status' => $this->claimed_status,
            'note' => $this->note,
            'report' => $this->whenLoaded('developmentReport', fn () => $this->developmentReport ? [
                'id' => $this->developmentReport->id,
                'summary' => $this->developmentReport->summary,
                'agent' => $this->developmentReport->agent,
                'reported_at' => $this->developmentReport->reported_at,
            ] : null),
            'created_at' => $this->created_at,
        ];
    }
}
