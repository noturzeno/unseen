<?php

namespace Database\Factories;

use App\Enums\ClaimSource;
use App\Enums\DiagramType;
use App\Models\Feature;
use App\Models\FeatureDiagram;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureDiagram>
 */
class FeatureDiagramFactory extends Factory
{
    public function definition(): array
    {
        return [
            'feature_id' => Feature::factory(),
            'type' => DiagramType::Flowchart,
            'title' => 'Main flow',
            'definition' => "flowchart TD\n    a[Start] --> b[End]",
            'source' => ClaimSource::UserEntered,
        ];
    }
}
