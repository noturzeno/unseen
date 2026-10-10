<?php

namespace Database\Factories;

use App\Enums\FeatureStatus;
use App\Enums\VerificationStatus;
use App\Models\Feature;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Feature>
 */
class FeatureFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'name' => fake()->words(3, true),
            'description' => fake()->sentence(),
        ];
    }

    public function withStatus(FeatureStatus $status, VerificationStatus $verification = VerificationStatus::NotChecked): static
    {
        return $this->state(fn () => [
            'status' => $status,
            'verification_status' => $verification,
        ]);
    }
}
