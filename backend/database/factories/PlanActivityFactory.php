<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanActivity> */
class PlanActivityFactory extends Factory
{
    /**
     * created_at is set explicitly because PlanActivity has $timestamps = false, so Eloquent
     * fills nothing in and the column is NOT NULL.
     *
     * actor_id takes a User factory even though plan_activity.actor_id is not a foreign key
     * (deliberately - see the model): the column still holds a real user's id until §9.3
     * anonymises it.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'actor_id' => User::factory(),
            'activity_type' => 'plan.created',
            'metadata' => [],
            'created_at' => now(),
        ];
    }
}
