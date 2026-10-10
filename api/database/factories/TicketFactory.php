<?php

namespace Database\Factories;

use App\Enums\ClaimSource;
use App\Enums\TicketStatus;
use App\Enums\TicketType;
use App\Models\Project;
use App\Models\Ticket;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Ticket>
 */
class TicketFactory extends Factory
{
    public function definition(): array
    {
        return [
            'project_id' => Project::factory(),
            'title' => ucfirst(fake()->words(4, true)),
            'type' => TicketType::Task,
            'status' => TicketStatus::Open,
            'source' => ClaimSource::AgentReported,
        ];
    }
}
