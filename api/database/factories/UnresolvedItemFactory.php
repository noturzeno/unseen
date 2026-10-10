<?php

namespace Database\Factories;

use App\Models\Project;
use App\Models\UnresolvedItem;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UnresolvedItem>
 */
class UnresolvedItemFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'text' => fake()->sentence(),
            'status' => UnresolvedItem::OPEN,
        ];
    }
}
