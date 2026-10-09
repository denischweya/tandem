<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\UserProfile;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserProfile>
 */
class UserProfileFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'display_name' => fake()->name(),
            'timezone' => 'Europe/London',
            'locale' => 'en',
        ];
    }
}
