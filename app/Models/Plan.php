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
     * $guarded = [], matching UserProfile, Device, RefreshToken, PasswordResetToken and
     * OutboxEvent, rather than the narrow #[Fillable] User carries. The reasoning is
     * recorded once, here, for all five models this task adds:
     *
     * Mass-assignment protection guards exactly one path - an array of client-shaped input
     * reaching ->fill() or ::create() - and that path is architecturally closed in this
     * codebase: spec §6 forbids a controller touching Eloquent at all, enforced by the
     * "controllers touch no Eloquent and no persistence" arch rule, so every write to these
     * tables goes through an Application use case holding an explicit attribute array (see
     * Register::execute(), which assigns field by field). On the write paths that actually
     * exist today - DeleteAccount's ownership transfers - the authority columns are set with
     * forceFill(), which ignores $fillable and $guarded entirely, so a narrow list would
     * protect nothing while making every use case and factory fight it.
     *
     * User's #[Fillable(['email'])] is not the counter-example it looks like: it exists to
     * keep password_hash un-fillable, a credential column, which none of these five has.
     *
     * The tripwire, so this does not rot into an assumption: the first time a use case
     * passes a validated() array straight into Plan::create() or ->fill(), owner_id, status
     * and plan_members.role become client-settable and this posture has to change in the
     * same commit.
     *
     * @var list<string>
     */
    protected $guarded = [];

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
