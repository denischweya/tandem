<?php

declare(strict_types=1);

namespace App\Domain\Auth;

enum PasswordResetState
{
    case Valid;
    case Used;
    case Expired;
}
