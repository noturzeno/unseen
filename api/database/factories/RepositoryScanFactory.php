<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\RepositoryScan;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<RepositoryScan>
 */
class RepositoryScanFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'scanner' => 'unseen-cli 0.1.0',
        ];
    }
}
