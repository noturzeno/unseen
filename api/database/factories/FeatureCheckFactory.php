<?php

namespace Database\Factories;

use App\Models\Feature;
use App\Models\FeatureCheck;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureCheck>
 */
class FeatureCheckFactory extends Factory
{
    public function definition(): array
    {
        return [
            'feature_id' => Feature::factory(),
            'pattern' => 'Tests\\Feature\\'.fake()->unique()->word().'Test',
        ];
    }
}
