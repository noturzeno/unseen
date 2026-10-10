<?php

namespace Database\Factories;

use App\Models\FeatureSuggestion;
use App\Models\Project;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FeatureSuggestion>
 */
class FeatureSuggestionFactory extends Factory
{
    public function definition(): array
    {
        $name = fake()->unique()->words(2, true);

        return [
            'project_id' => Project::factory(),
            'key' => FeatureSuggestion::normalizeKey($name),
            'name' => ucfirst($name),
            'status' => 'pending',
            'evidence' => [['type' => 'route', 'path' => 'routes/web.php', 'detail' => 'GET /'.str_replace(' ', '-', $name)]],
        ];
    }
}
