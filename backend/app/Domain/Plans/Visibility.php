<?php

declare(strict_types=1);

namespace App\Domain\Plans;

/**
 * Blueprint §8, which states that MVP should not support public Plans. There are therefore
 * exactly two cases, and a third added here would be a product decision smuggled in as an
 * enum - EnumTest.php asserts the whole list for that reason rather than spot-checking it.
 *
 * `invite_only` is plans.visibility's column default: a Plan is shared with the people who
 * were invited to it, and `private` is the narrower "only me" state.
 */
enum Visibility: string
{
    case Private = 'private';
    case InviteOnly = 'invite_only';
}
