<?php

namespace Database\Factories;

use App\Models\FeatureCategory;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureCategory>
 */
class FeatureCategoryFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'position' => 0,
        ];
    }
}
