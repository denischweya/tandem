<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Models\User;
use DateTimeImmutable;

final readonly class Session
{
    public function __construct(
        public string $accessToken,
        public DateTimeImmutable $accessTokenExpiresAt,
        public string $refreshToken,
        public DateTimeImmutable $refreshTokenExpiresAt,
        public User $user,
    ) {}
}
