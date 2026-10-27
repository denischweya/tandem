<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Shared\Clock;
use App\Models\Device;
use App\Models\RefreshToken;
use App\Models\User;

final readonly class RefreshTokenIssuer
{
    public const TTL_DAYS = 30;

    public function __construct(private Clock $clock) {}

    public function issue(User $user, ?Device $device): IssuedRefreshToken
    {
        // 32 bytes = 256 bits (spec §9.1), hex-encoded so it is safe in a JSON body, a header
        // and a cookie without further escaping.
        $plaintext = bin2hex(random_bytes(32));

        $model = RefreshToken::create([
            'user_id' => $user->id,
            'device_id' => $device?->id,
            // Only the hash is stored. A database dump therefore cannot be replayed, and
            // sha256 is right here rather than bcrypt: the token is 256 bits of entropy from
            // a CSPRNG, so there is nothing to brute-force and the lookup must be indexable.
            'token_hash' => hash('sha256', $plaintext),
            'expires_at' => $this->clock->now()->modify('+'.self::TTL_DAYS.' days'),
        ]);

        return new IssuedRefreshToken($plaintext, $model);
    }
}
