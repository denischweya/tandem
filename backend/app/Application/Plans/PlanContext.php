<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Models\Plan;

/**
 * Spec §4.3's resolved triple, plus the member count. It exists so the authorization inputs are
 * resolved once per request and handed to PlanPermissions as enums - "rules operate on value
 * objects and enums, never on models".
 *
 * It is in Application and not Domain because it names App\Models\Plan, which the arch rule
 * toOnlyUse(['App\Domain', 'DateTimeImmutable', 'DateTimeZone', 'InvalidArgumentException'])
 * does not admit.
 *
 * memberCount is here and not computed by the caller because §6.1 publishes member_count on
 * PlanResource for the single Plan read AND for every row of GET /plans, and the only place it
 * can be read without the N+1 over members that §6.1 names as the obvious trap is the same
 * query that resolved the membership.
 *
 * role and status are enums, converted from the plain string columns by the repository, NOT by
 * an Eloquent cast - see EloquentPlanRepository::contextFor() for the two measurements behind
 * that.
 */
final readonly class PlanContext
{
    public function __construct(
        public Plan $plan,
        public int $memberCount,
        public MemberRole $role,
        public MemberStatus $status,
    ) {}
}
