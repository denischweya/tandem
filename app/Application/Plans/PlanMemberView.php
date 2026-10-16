<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use Illuminate\Support\Carbon;

/**
 * One row of §8.1's member list: the five scalars PlanMemberResource publishes - userId,
 * displayName, role, status and joinedAt - and nothing else.
 *
 * Five typed scalars rather than the PlanMember model, because a resource reading through the
 * model would reach $member->role and $member->status as the raw database strings instead of
 * the enums the rest of the Application layer compares with PlanPermissions, and because a
 * model property is free to carry columns (soft-delete timestamps, foreign keys) a read model
 * has no business publishing.
 *
 * displayName is carried here rather than reached through a relation because the repository
 * resolves it with one join for the whole page - §8.1's "one eager load, not an N+1".
 *
 * status is always MemberStatus::Active: the query that builds this filters `status = 'active'`
 * (§8.1's "the list contains status = active rows only"), so the field is here for
 * PlanMemberResource to publish, not because any other case can reach this constructor.
 *
 * joinedAt is a non-nullable Carbon although plan_members.joined_at is a nullable column: the
 * query that builds this excludes NULL rows (S-37), so every instance has one, and declaring
 * it here is what stops PlanMemberResource inventing its own answer for a case it can never
 * see.
 */
final readonly class PlanMemberView
{
    public function __construct(
        public string $userId,
        public string $displayName,
        public MemberRole $role,
        public MemberStatus $status,
        public Carbon $joinedAt,
    ) {}
}
