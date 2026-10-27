<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Plans\MemberRole;
use App\Models\Plan;
use App\Models\PlanInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<PlanInvite> */
class PlanInviteFactory extends Factory
{
    /**
     * plan_id and invited_by both default to a factory so this is usable on its own, and both
     * are the attributes callers overwrite most often - a Factory instance left in place by an
     * override is never resolved, so passing explicit ids creates no stray Plan or User.
     *
     * `email` comes from fake()->unique()->safeEmail(), which is lowercase by construction
     * (Faker's Internet::userName() lowercases), and it has to be: plan_invites carries
     * CHECK (email IS NULL OR email = lower(email)), so a mixed-case value is a 23514 at
     * insert rather than a bad row. UserFactory relies on the same property against users'
     * identical CHECK.
     *
     * token_hash is a sha256 of 32 random bytes - RefreshTokenFactory's and
     * PasswordResetTokenFactory's shape, and §10.1's real token shape, so the UNIQUE index on
     * the column is never the thing that fails a test.
     *
     * expires_at is 14 days out, which is §10.2's TTL. The factory deliberately does not read
     * PlanInviteTokens::TTL_DAYS: a factory's job is a plausible row, and coupling it to the
     * production constant would mean a TTL change silently rewriting every fixture's meaning.
     * What matters here is only that the default row is live.
     *
     * role defaults to the column default, member, rather than to the more interesting admin -
     * PlanMemberFactory's rule: a seeded row should look like a row the application would
     * really have written.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'plan_id' => Plan::factory(),
            'invited_by' => User::factory(),
            'email' => fake()->unique()->safeEmail(),
            'token_hash' => hash('sha256', bin2hex(random_bytes(32))),
            'role' => MemberRole::Member->value,
            'expires_at' => now()->addDays(14),
        ];
    }

    /** §10.2: expiry is one of InvitePolicy's three reject reasons. */
    public function expired(): static
    {
        return $this->state(fn () => ['expires_at' => now()->subMinute()]);
    }

    /** §10.8: revocation sets revoked_at and never deletes the row. */
    public function revoked(): static
    {
        return $this->state(fn () => ['revoked_at' => now()->subMinute()]);
    }

    /**
     * Both columns together, never one without the other: §10.7 step 6 claims an invite by
     * writing accepted_at AND accepted_by, and a row with one of them is a row no code path
     * produces. accepted_by is the user who actually joined, which §10.6 deliberately does not
     * require to match `email`.
     */
    public function accepted(): static
    {
        return $this->state(fn () => [
            'accepted_at' => now()->subMinute(),
            'accepted_by' => User::factory(),
        ]);
    }

    /** §10.5's prioritised channel: email IS NULL, which the CHECK allows through by construction. */
    public function linkOnly(): static
    {
        return $this->state(fn () => ['email' => null]);
    }

    /**
     * For a test that holds the plaintext. Only the sha256 is stored (§10.1), so there is no
     * other way to seed a row a known token opens - and hashing it here rather than in each
     * test is what stops one of them hashing it differently.
     */
    public function forToken(string $plainToken): static
    {
        return $this->state(fn () => ['token_hash' => hash('sha256', $plainToken)]);
    }
}
