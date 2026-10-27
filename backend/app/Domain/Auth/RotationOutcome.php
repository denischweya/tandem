<?php

declare(strict_types=1);

namespace App\Domain\Auth;

enum RotationOutcome
{
    case Rotate;
    case RevokeChain;
    case Reject;
}
