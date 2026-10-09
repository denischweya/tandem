<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Plans\PlanStatus;
use App\Domain\Plans\Visibility;
use App\Models\Plan;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Plan> */
class PlanFactory extends Factory
{
    /**
     * status and visibility are written from the enums rather than as literals, so a case
     * renamed without the column default being migrated breaks here instead of silently
     * seeding a value nothing recognises.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'title' => fake()->sentence(3),
            'timezone' => 'Europe/London',
            'status' => PlanStatus::Active->value,
            'visibility' => Visibility::InviteOnly->value,
        ];
    }
}
