<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\PlanFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * The minimum Plan model spec §9.3 needs, not the Wave 2 one: account deletion cannot
 * transfer Plan ownership without being able to read and write plans.owner_id, so this
 * exists here. Wave 2 extends it with the endpoints, policies and read model.
 *
 * NOTHING IS MASS-ASSIGNABLE ON THIS MODEL, OR ON PlanMember. The reasoning is recorded once,
 * here, for both:
 *
 * This replaces a prose tripwire. The previous posture was $guarded = [] plus a docblock
 * describing the condition under which it would have to change, and the argument for it was
 * that the only path mass assignment guards - a client-shaped array reaching ->fill() or
 * ::create() - is architecturally closed, since spec §6 forbids a controller touching
 * Eloquent and the "controllers touch no Eloquent and no persistence" arch rule enforces it.
 * That argument is true of the arch rule and does not reach the shape Wave 2 will have: a
 * controller passing $request->validated() into an Application use case which calls
 * Plan::create($validated) breaks no rule in this codebase, and makes plans.owner_id,
 * plans.status and plan_members.role client-settable. A comment is not a mechanism.
 *
 * $guarded = ['*'], and three other spellings were tried and measured first:
 *
 *   - #[Fillable([])], an empty allowlist, is a NO-OP. GuardsAttributes::fillableFromArray()
 *     short-circuits on count($fillable) === 0 and isFillable() falls through to "empty
 *     fillable and not guarded", so an empty allowlist permits everything. Probed directly:
 *     ->fill(['owner_id' => 'x', 'status' => 'active']) set both attributes.
 *   - #[Guarded(['*'])] works, but only by accident of this model not declaring the property,
 *     and it can be defeated SILENTLY. initializeGuardsAttributes() consults the attribute
 *     only `if ($this->guarded === $default)` - so re-adding `protected $guarded = []` makes
 *     the attribute inert with no error anywhere, which is the same class of failure as the
 *     prose tripwire this replaces.
 *   - simply DELETING `protected $guarded = []` also works, because Eloquent's own default is
 *     ['*'] - and leaves the posture invisible at the model, readable only by someone who
 *     knows that default.
 *
 * So the value that is actually in force is written down, in the one place that cannot be
 * overridden from elsewhere. ['*'] makes totallyGuarded() true, at which point Model::fill()
 * THROWS MassAssignmentException on the first attribute rather than discarding it silently -
 * loud at the moment of the mistake, which is the point.
 *
 * The mechanism is tests/Feature/Models/PlanTest.php's two mass-assignment cases, not this
 * comment: re-adding `protected $guarded = []` to either model fails them.
 *
 * forceFill() is unaffected, which is why this costs nothing: every write that exists today -
 * DeleteAccount's ownership transfers, the day-30 purge - already goes through forceFill() or
 * an explicit assignment. Verified by grep over app/: there is no Plan::create(),
 * PlanMember::create() or ->fill() in production code at all. Factories are unaffected too,
 * for a different reason - Illuminate's Factory wraps instantiation in Model::unguarded().
 *
 * UserProfile, Device, RefreshToken, PasswordResetToken and OutboxEvent keep $guarded = [].
 * They hold no authority column a client could usefully set, nothing in a later cycle writes
 * them from validated input, and User's own #[Fillable(['email'])] already covers the one
 * credential column in this schema. Widening that decision is a separate question from this
 * one.
 *
 * $status and $visibility are deliberately plain strings rather than being cast to
 * App\Domain\Plans\PlanStatus and Visibility. Nothing in Cycle 1 reads either as a domain
 * type - §9.3 writes PlanStatus::Archived->value and that is all - and an enum cast turns
 * any unrecognised database value into a ValueError during hydration, which is a decision
 * for whichever plan owns the write endpoints, taken together with them.
 *
 * @property string $id
 * @property string $owner_id
 * @property string|null $group_id
 * @property string $title
 * @property string|null $description
 * @property string $status
 * @property string $visibility
 * @property string $timezone
 * @property string|null $cover_image_path
 * @property Carbon|null $archived_at
 * @property Carbon $created_at
 * @property Carbon $updated_at
 * @property-read Collection<int, PlanMember> $members
 */
final class Plan extends Model
{
    /** @use HasFactory<PlanFactory> */
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
        return ['archived_at' => 'datetime'];
    }

    /** @return HasMany<PlanMember, $this> */
    public function members(): HasMany
    {
        return $this->hasMany(PlanMember::class);
    }

    /** @return BelongsTo<User, $this> */
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    /**
     * Nullable, and that is the whole point of the column: a Plan need not come from a
     * Group, and deleting a Group sets this to NULL rather than deleting the Plan - which is
     * what makes §9.3 step 3's "delete a Group nobody is left in" safe. See the migration.
     *
     * @return BelongsTo<Group, $this>
     */
    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class);
    }
}
