<?php

namespace Database\Factories;

use App\Enums\ClaimSource;
use App\Models\Project;
use App\Models\SpecMismatch;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<SpecMismatch>
 */
class SpecMismatchFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => ucfirst(fake()->words(4, true)),
            'prd_says' => 'Users pick a plan first.',
            'design_says' => 'Users see prices before picking a plan.',
            'built_to' => 'design',
            'decide_by' => 'team',
            'status' => 'open',
            'source' => ClaimSource::AgentReported,
        ];
    }
}
