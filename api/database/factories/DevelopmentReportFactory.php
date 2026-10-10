<?php

namespace Database\Factories;

use App\Enums\ClaimSource;
use App\Models\DevelopmentReport;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<DevelopmentReport>
 */
class DevelopmentReportFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'source' => ClaimSource::AgentReported,
            'agent' => 'claude-code',
            'summary' => fake()->sentence(),
            'changed_files' => [['path' => 'app/Example.php', 'change' => 'modified']],
            'tests' => [['command' => 'php artisan test', 'outcome' => 'passed']],
            'unresolved' => [],
            'reported_at' => now(),
        ];
    }
}
