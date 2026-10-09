<?php

declare(strict_types=1);

namespace App\Domain\Plans;

/**
 * Blueprint §9. Case order is the permission hierarchy, strongest first.
 *
 * No `use` statement in this file, or in any of its three siblings: app/Domain imports
 * nothing from Laravel, Eloquent or any vendor SDK, and three guards enforce it (the
 * toOnlyUse() arch rule plus the two phpstan-rules/DomainPurity rules). An enum is the
 * right shape for these four because they are closed sets the database already constrains
 * by default value - see tests/Unit/Domain/Plans/EnumTest.php, which pins each case list
 * against the blueprint and against those defaults.
 */
enum MemberRole: string
{
    case Owner = 'owner';
    case Admin = 'admin';
    case Member = 'member';
}
