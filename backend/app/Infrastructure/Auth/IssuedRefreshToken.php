<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Models\RefreshToken;

final readonly class IssuedRefreshToken
{
    public function __construct(
        public string $plaintext,
        public RefreshToken $model,
    ) {}
}
