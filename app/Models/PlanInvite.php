<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanInviteFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * plan_invites has existed since the Wave 0 freeze with no model, no factory and no writer -
 * before this wave touched it, the only reference anywhere in app/ was a comment in
 * PurgeDeletedUsersCommand. This is the first writer's model, and §10 is the whole of its
 * behaviour.
 *
 * No $table property, deliberately, and the absence is worth a sentence because PlanActivity
 * next door declares one: Laravel's pluraliser maps PlanInvite to plan_invites, which is the
 * table. PlanActivity's table is singular, which is why that model has to say so.
 *
 * NOTHING IS MASS-ASSIGNABLE. ['*'], not [], and not #[Fillable([])] - Plan's docblock carries
 * the three measurements behind that choice, including that an empty allowlist is a no-op that
 * permits everything. This model takes Plan's posture rather than PasswordResetToken's because
 * it holds two authority columns a client would like to set: `role`, which decides whether the
 * accepting user becomes an admin (§10.4), and `token_hash`, which is a credential.
 *
 * The consequence is load-bearing for App\Infrastructure\Auth\PlanInviteTokens:
 * PlanInvite::create([...]) THROWS MassAssignmentException, so the line
 * PasswordResetTokens::issue() uses cannot be copied. Assign field by field, or forceFill().
 * And guarding does not stop an OMITTED field taking its column default - `role` defaults to
 * 'member' in the database - so every write assigns `role` explicitly.
 *
 * There is no invitedBy() and no acceptedBy() relation, for PlanActivity::actor()'s reason:
 * both columns are nullable with nullOnDelete, fired by the day-30 purge's forceDelete(), so a
 * relation would be a guaranteed-looking model that is null on a row GET /plans/{plan}/invites
 * still lists. The display name for a list will be resolved by one left join in whichever
 * repository implements the invite list, where the nullability can stay visible in the type -
 * that repository does not exist yet.
 *
 * The class docblock's `@property-read Plan $plan` tag below is non-nullable deliberately, the
 * opposite call from the paragraph above: plan_id is NOT NULL with cascadeOnDelete, not
 * nullOnDelete. Without that tag, Larastan's relation-magic-property extension infers `plan()`'s
 * BelongsTo<Plan, $this> as Plan|null under PHPStan level 8. The analyser escape this tag takes
 * is the one `User::profile()`'s own `@property-read UserProfile $profile` already takes for its
 * HasOne - but the two are not the same claim, and this one is the stronger of the pair.
 * `user_profiles.user_id` is UNIQUE NOT NULL, which guarantees at most one profile per user, not
 * at least one: a persisted User with no profile row returns null for `->profile` (nothing
 * forbids creating one without ->hasProfile()), and that invariant is registration's doing, not
 * the schema's. `plan_invites.plan_id` is NOT NULL on PlanInvite's OWN row, not on the far side
 * of the relation, so a persisted plan_invites row cannot exist without a satisfied foreign key
 * - and Plan carries no soft-deletes and no global scope, so nothing can make the FK point past a
 * row this relation would fail to find. There is no path where the FK stays satisfied and
 * `->plan` resolves null, for a persisted row.
 *
 * "For a persisted row" is the tag's whole claim, not the whole truth about this property, and
 * the gap is worth naming rather than leaving for the next reader to discover: `(new
 * PlanInvite)->plan` on an unpersisted instance is null (plan_id is unset), and an in-memory
 * instance holding a plan_id whose Plan was cascade-deleted by another connection after this one
 * read the row is also null - the tag covers neither, silently, because PHPStan has no way to
 * express "non-null only once saved."
 *
 * @property string $id
 * @property string $plan_id
 * @property string|null $invited_by
 * @property string|null $email
 * @property string $token_hash
 * @property string $role
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property string|null $accepted_by
 * @property Carbon|null $revoked_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Plan $plan
 */
final class PlanInvite extends Model
{
    /** @use HasFactory<PlanInviteFactory> */
    use HasFactory, HasUuids;

    /**
     * Nothing is mass-assignable. ['*'], not [] - see the class docblock.
     *
     * @var list<string>
     */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Plan, $this> */
    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }
}
