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

    // There is deliberately NO replacedBy() relation for $replaced_by_id, and adding one back
    // would be a regression rather than a convenience. Its only caller was
    // RefreshTokenRotator::revokeChainFrom(), which must follow this link with a locking read:
    // a plain relation load is an unlocked READ COMMITTED snapshot, and readers do not block on
    // FOR UPDATE in Postgres, so a chain walk through the relation can miss a successor a
    // concurrent refresh is in the middle of creating - leaving a live refresh token behind
    // after a detected replay. See that method's docblock for the interleaving.
    //
    // Removing the accessor is what makes the safe form the only reachable one: following the
    // link now means writing the query, and the query is where lockForUpdate() lives. The
    // column itself stays first-class as $replaced_by_id above, and the migration records why
    // it is not a self-referential foreign key (the chain writes the link before the successor
    // row exists in some orderings).
}
