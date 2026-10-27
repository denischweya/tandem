<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Shared\Clock;
use App\Models\User;

final readonly class TokenIssuer
{
    public const TTL_MINUTES = 15;

    public function __construct(private Clock $clock) {}

    /**
     * The expiry is written onto the token row rather than coming from
     * config('sanctum.expiration'). Sanctum's Guard checks `! $accessToken->expires_at ||
     * ! $accessToken->expires_at->isPast()` independently of the global setting, so a
     * per-token expiry is authoritative, visible in the database, and testable by minting one
     * in the past - which the global config cannot be.
     */
    public function issue(User $user): IssuedAccessToken
    {
        $expiresAt = $this->clock->now()->modify('+'.self::TTL_MINUTES.' minutes');

        $token = $user->createToken('api', ['*'], $expiresAt);

        return new IssuedAccessToken($token->plainTextToken, $expiresAt);
    }
}
