<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Models\PlanMember;
use Illuminate\Support\Carbon;
use RuntimeException;

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

    /**
     * Takes the raw model plus a display name rather than a composite view type, so this
     * factory depends on nothing the repositories task might reshape later. Used by
     * PlanMemberResourceTest and by any future single-member read; listActiveMembers() builds
     * its views directly from its joined row and never calls this.
     */
    public static function fromMember(PlanMember $member, string $displayName): self
    {
        if ($member->joined_at === null) {
            // D-R8 / baseline S-37: status = 'active' => joined_at IS NOT NULL is an
            // application invariant with no database CHECK behind it (freeze). Coalescing to
            // "now" or to the member row's created_at would publish a joined_at Wave 2 never
            // wrote, which is worse than failing loudly here.
            throw new RuntimeException(
                'PlanMember carried a NULL joined_at for an active row. The invariant '
                .'status = active => joined_at IS NOT NULL was violated upstream (baseline S-37).'
            );
        }

        return new self(
            userId: $member->user_id,
            displayName: $displayName,
            role: MemberRole::from($member->role),
            status: MemberStatus::from($member->status),
            joinedAt: $member->joined_at,
        );
    }
}
