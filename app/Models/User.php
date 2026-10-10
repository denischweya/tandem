<?php

declare(strict_types=1);

namespace App\Models;

use App\Domain\Shared\Clock;
use App\Infrastructure\Outbox\OutboxWriter;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Sanctum\HasApiTokens;

/**
 * @property string $id
 * @property string $email
 * @property string $password_hash
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $suspended_at
 * @property Carbon|null $deleted_at
 * @property-read UserProfile $profile
 */
#[Fillable(['email'])]
#[Hidden(['password_hash'])]
class User extends Authenticatable implements MustVerifyEmail
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, HasUuids, Notifiable, SoftDeletes;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            // The column is password_hash, not password - Laravel's auth guard still calls
            // getAuthPassword() below, which this cast backs onto the real column. Hash::isHashed()
            // makes the cast idempotent, so it is harmless defence-in-depth alongside the
            // explicit Hash::make() the registration use case calls (ADR 0001 governs where
            // that decision lives, not whether the model may also carry this net).
            'password_hash' => 'hashed',
        ];
    }

    /** @return HasOne<UserProfile, $this> */
    public function profile(): HasOne
    {
        return $this->hasOne(UserProfile::class);
    }

    /** @return HasMany<Device, $this> */
    public function devices(): HasMany
    {
        return $this->hasMany(Device::class);
    }

    /** @return HasMany<RefreshToken, $this> */
    public function refreshTokens(): HasMany
    {
        return $this->hasMany(RefreshToken::class);
    }

    /**
     * Plans this user OWNS, not Plans they are in - plans.owner_id, which is NOT NULL with
     * RESTRICT, so this relation is also the set spec §9.3 step 2 has to empty before the
     * account can ever be hard-deleted. Memberships are planMemberships() below.
     *
     * @return HasMany<Plan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(Plan::class, 'owner_id');
    }

    /** @return HasMany<PlanMember, $this> */
    public function planMemberships(): HasMany
    {
        return $this->hasMany(PlanMember::class);
    }

    /**
     * Groups this user owns; see plans() for why owning and belonging are separate relations.
     *
     * @return HasMany<Group, $this>
     */
    public function groups(): HasMany
    {
        return $this->hasMany(Group::class, 'owner_id');
    }

    /** @return HasMany<GroupMember, $this> */
    public function groupMemberships(): HasMany
    {
        return $this->hasMany(GroupMember::class);
    }

    /**
     * Get the password for the user, from the real column name.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    /**
     * The four MustVerifyEmail methods, implemented directly rather than by using Illuminate's
     * MustVerifyEmail trait. The trait supplies sendEmailVerificationNotification() backed by
     * the notification system, which would bypass the outbox - the one path every email in
     * this application takes (§85). Writing it out keeps that single path and makes the bypass
     * impossible to take by accident.
     *
     * The interface itself is load-bearing and cannot be dropped to be rid of the two
     * unreachable methods below: Illuminate\Auth\Middleware\EnsureEmailIsVerified gates on
     * `$request->user() instanceof MustVerifyEmail && ! $request->user()->hasVerifiedEmail()`,
     * so removing it would silently turn the `verified` middleware into a no-op. PHP then
     * requires all four methods, whether or not anything calls them.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /**
     * Shares its instant source with VerifyEmail::execute(), where it used to be a second
     * implementation of the same write.
     *
     * It had no caller and no test, and it diverged from the one that does: it stamped
     * $this->freshTimestamp() while VerifyEmail::execute() uses the injected Clock that every
     * other timestamp in this application goes through - so a frozen clock in a test moved one
     * and not the other. The first fix called app(VerifyEmail::class)->execute($this) instead,
     * which removed the divergence but opened a worse hole: a model reaching into App\Application
     * is a dependency inversion (and, since VerifyEmail::execute() takes a User, a class-level
     * cycle) that nothing in tests/Architecture/DomainPurityTest.php caught, because its rules
     * constrain App\Domain and App\Http\Controllers and say nothing about App\Models. That gap is
     * closed below by a sixth rule.
     *
     * Resolving Clock - a Domain contract - is the fix that keeps the single-source-of-truth
     * benefit without the inversion: a model depending on App\Domain is the sane direction this
     * codebase already takes everywhere else, and it is the same Clock instance
     * VerifyEmail::execute() resolves, so the two still cannot write different instants. The
     * idempotence check is copied rather than shared, because the fix this replaces is the thing
     * being removed, not something to route back through.
     *
     * Resolved from the container rather than injected, because a model's constructor belongs
     * to Eloquent. The same reasoning, and the same `app()` call, as
     * sendEmailVerificationNotification() below.
     *
     * Returns true unconditionally: the contract's return value is "did this succeed", and this
     * method has no failure mode.
     */
    public function markEmailAsVerified(): bool
    {
        if ($this->email_verified_at === null) {
            $this->forceFill(['email_verified_at' => app(Clock::class)->now()])->save();
        }

        return true;
    }

    /**
     * CANNOT delegate, and the reason is that there is nothing to delegate to.
     *
     * This is the only "ask for a verification email" entry point in the codebase. Register
     * writes the same event, but from inside its own transaction and as part of creating the
     * account - §85's point being that the event and the row commit together - so it is not a
     * seam this method could call. A "resend verification email" endpoint is the obvious future
     * caller, and this is where it would land.
     *
     * So what is left is one line duplicated with Register::execute(), and the risk is that the
     * two stop agreeing on the event type or the payload - at which point the dispatcher would
     * report "No handler registered" for one of them for ever. That is pinned by
     * tests/Feature/Models/UserTest.php's "writes the same user.registered event Register
     * does", which compares the two rows rather than restating either.
     */
    public function sendEmailVerificationNotification(): void
    {
        app(OutboxWriter::class)->write('User', $this->id, 'user.registered', ['user_id' => $this->id]);
    }

    public function getEmailForVerification(): string
    {
        return $this->email;
    }
}
