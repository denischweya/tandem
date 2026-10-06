<?php

declare(strict_types=1);

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['email'])]
#[Hidden(['password_hash'])]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable, SoftDeletes;

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

    /**
     * Get the password for the user, from the real column name.
     */
    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }
}
