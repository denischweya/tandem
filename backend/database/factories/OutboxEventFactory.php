<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<OutboxEvent>
 */
class OutboxEventFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'aggregate_type' => 'User',
            'aggregate_id' => fake()->uuid(),
            'event_type' => 'user.registered',
            'payload' => [],
            'occurred_at' => now(),
        ];
    }
}
