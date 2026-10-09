<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanMember> */
class PlanMemberFactory extends Factory
{
    /**
     * Both ids default to a factory so this is usable on its own, and both are the attributes
     * callers overwrite most often - a Factory instance left in place by an override is never
     * resolved, so passing explicit ids creates no stray Plan or User.
     *
     * role and status default to the plan_members column defaults (member/invited) rather
     * than to the more convenient active member: a seeded row should look like a row the
     * application would really have written at the start of its lifecycle. Tests that need
     * an accepted member say so - see the member() helper in DeleteAccountTest.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'user_id' => User::factory(),
            'role' => MemberRole::Member->value,
            'status' => MemberStatus::Invited->value,
            'joined_at' => null,
        ];
    }
}
