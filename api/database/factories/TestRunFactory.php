<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\TestRun;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<TestRun>
 */
class TestRunFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'runner' => 'unseen-cli 0.1.0',
            'git_commit' => 'abc1234',
            'feature_outcomes' => [],
        ];
    }
}
