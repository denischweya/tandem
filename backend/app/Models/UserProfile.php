<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserProfileFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property string $id
 * @property string $user_id
 * @property string $display_name
 * @property string|null $avatar_path
 * @property string $timezone
 * @property string $locale
 */
final class UserProfile extends Model
{
    /** @use HasFactory<UserProfileFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
