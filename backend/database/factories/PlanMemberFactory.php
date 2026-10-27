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
     * an accepted member say so - see the member() helper in tests/Pest.php, where it moved
     * from DeleteAccountTest once a second suite needed it too.
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

    /**
     * status = active AND joined_at together, which is the invariant the members list depends
     * on: plan_members.joined_at is NULLable and is the leading key of §6.3's members cursor,
     * so an active row without one appears on page 1 and is then dropped from every page after
     * it (a keyset predicate over a NULL evaluates to NULL). The application's writers always
     * set it; this state is what stops a fixture being the exception.
     */
    public function active(): static
    {
        return $this->state(fn () => [
            'status' => MemberStatus::Active->value,
            'joined_at' => now(),
        ]);
    }

    /**
     * §8.3's LeavePlan: status = left, role untouched. A departing member keeps the role they
     * held, because MemberStatus's docblock makes `left` the record of their own choice and
     * §8.3 only demotes on the other path.
     */
    public function left(): static
    {
        return $this->state(fn () => [
            'status' => MemberStatus::Left->value,
            'joined_at' => now()->subDay(),
        ]);
    }

    /**
     * §8.3's RemoveMember: status = removed AND role demoted to member, both, because "a
     * removed admin whose row still says admin is a row claiming authority its status denies".
     * Chain ->role(...) after this one to override it deliberately; states apply in order.
     */
    public function removed(): static
    {
        return $this->state(fn () => [
            'status' => MemberStatus::Removed->value,
            'role' => MemberRole::Member->value,
            'joined_at' => now()->subDay(),
        ]);
    }

    /** The role as the enum, never as a literal, so a renamed case breaks here rather than seeding a value nothing recognises. */
    public function role(MemberRole $role): static
    {
        return $this->state(fn () => ['role' => $role->value]);
    }
}
