<?php

declare(strict_types=1);

namespace App\Models;

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
     * Implemented directly rather than by using Illuminate's MustVerifyEmail trait. The trait
     * supplies sendEmailVerificationNotification() backed by the notification system, which
     * would bypass the outbox - the one path every email in this application takes (§85).
     * Writing it out keeps that single path and makes the bypass impossible to take by
     * accident.
     */
    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    public function markEmailAsVerified(): bool
    {
        return $this->forceFill(['email_verified_at' => $this->freshTimestamp()])->save();
    }

    public function sendEmailVerificationNotification(): void
    {
        app(OutboxWriter::class)->write('User', $this->id, 'user.registered', ['user_id' => $this->id]);
    }

    public function getEmailForVerification(): string
    {
        return $this->email;
    }
}
