<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/** The four states a presented refresh token can be in, resolved from the row by Infrastructure. */
enum RefreshTokenState
{
    case Active;
    case Rotated;
    case Revoked;
    case Expired;
}
