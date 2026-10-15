<?php

declare(strict_types=1);

namespace App\Domain\Plans;

/**
 * What InvitePolicy::decide() answers for a resolved InviteState. Unbacked: an in-memory
 * decision, never persisted as a column value.
 */
enum InviteOutcome
{
    case Accept;
    case Reject;
}
