<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use DateTimeImmutable;

final readonly class IssuedAccessToken
{
    public function __construct(
        public string $plaintext,
        public DateTimeImmutable $expiresAt,
    ) {}
}
