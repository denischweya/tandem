<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Models\Group;
use App\Models\GroupMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<GroupMember> */
class GroupMemberFactory extends Factory
{
    /**
     * No status and no joined_at, matching the table: joining a Group is not a two-step
     * invitation, so created_at is the join date.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'group_id' => Group::factory(),
            'user_id' => User::factory(),
        ];
    }
}
