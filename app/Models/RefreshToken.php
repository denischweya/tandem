<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\RefreshTokenFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * @property string $id
 * @property string $user_id
 * @property string|null $device_id
 * @property string $token_hash
 * @property Carbon $expires_at
 * @property Carbon|null $revoked_at
 * @property string|null $replaced_by_id
 */
final class RefreshToken extends Model
{
    /** @use HasFactory<RefreshTokenFactory> */
    use HasFactory, HasUuids;

    protected $guarded = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'revoked_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * The device this token is bound to, or null for a client that sends no install_id -
     * the web BFF (ADR 0004), which has no install_id concept. Nullable here because
     * refresh_tokens.device_id is nullable for exactly that reason; see the migration.
     *
     * @return BelongsTo<Device, $this>
     */
    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class);
    }

    /**
     * The successor minted when this token was rotated. Deliberately not a database foreign
     * key (see the migration comment): the rotation chain writes the link before the
     * successor exists in some orderings.
     *
     * @return BelongsTo<RefreshToken, $this>
     */
    public function replacedBy(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaced_by_id');
    }
}
