<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\MemberRole;
use App\Models\PlanInvite;
use Illuminate\Support\Carbon;

/**
 * One row of §10.8's invite list: the seven scalars PlanInviteResource publishes as id, email,
 * role, expires_at, accepted_at, revoked_at and invited_by_name - and nothing else.
 *
 * SEVEN TYPED SCALARS RATHER THAN THE PlanInvite MODEL, which is the same decision R3 made for
 * PlanMemberView and for the same reasons. A view that wrapped the model would have
 * PlanInviteResource read $this->invite->id and $this->invite->expires_at THROUGH this class into
 * the model, which is the layering the Application layer exists to stop being nominal; and
 * $invite->role is the raw varchar (plan_invites.role carries no cast on PlanInvite), so the
 * resource would publish the column where the rest of this layer compares MemberRole instances.
 * fromInvite() resolves that once, here, at the boundary. Note also what the model carries that
 * a read model has no business publishing: token_hash is a credential, and accepted_by is
 * another user's id.
 *
 * invitedByName is NULLABLE, and §6.1 omits that although it flags the same thing twice for
 * actor_name. plan_invites.invited_by is `foreignUuid('invited_by')->nullable()
 * ->constrained('users')->nullOnDelete()`, fired by the day-30 purge's forceDelete(); §14.1
 * clause 1 revokes only UNACCEPTED invites when an account is soft-deleted, so an accepted
 * invite keeps its row, stays listed by GET /plans/{plan}/invites, and loses invited_by thirty
 * days later. Publishing it as non-nullable would make the correction a visible contract
 * change later, because tandem-shared's WireEquivalent proofs are symmetric.
 *
 * The name is carried here rather than reached through a relation because PlanInvite
 * deliberately has no invitedBy() relation - see that model's docblock - and because the
 * repository resolves every name in the list with one join.
 *
 * expiresAt is non-nullable because plan_invites.expires_at is NOT NULL; acceptedAt and
 * revokedAt are nullable because those two columns are.
 */
final readonly class PlanInviteView
{
    public function __construct(
        public string $id,
        public ?string $email,
        public MemberRole $role,
        public Carbon $expiresAt,
        public ?Carbon $acceptedAt,
        public ?Carbon $revokedAt,
        public ?string $invitedByName,
    ) {}

    /**
     * $invitedByName is passed in, not coalesced and not reached through a relation: the
     * repository resolves every name on the page with one leftJoin, and baseline S-3 makes null
     * reachable through this wave's own list route.
     */
    public static function fromInvite(PlanInvite $invite, ?string $invitedByName): self
    {
        return new self(
            id: $invite->id,
            email: $invite->email,
            // from(), not tryFrom(). At THIS commit plan_invites.role has exactly one writer -
            // PlanInviteFactory::definition(), which assigns MemberRole::Member->value
            // explicitly - because App\Infrastructure\Auth\PlanInviteTokens, the intended
            // production writer, does not exist yet; it arrives in Task 25 and is specified to
            // assign MemberRole::...->value explicitly there too, for baseline S-2's reason (an
            // omitted `role` silently takes the column default 'member'). Both halves of that
            // sentence are true at this commit only because the first is present tense and the
            // second is a forward reference to a task that has not landed - do not read this as
            // a claim that PlanInviteTokens exists today.
            //
            // The choice itself is settled policy, not a local guess: a human ruling (received
            // 2026-10-19, pinned in Task 9's own test for MemberRole::from() on
            // plan_members.role) decided from() stays - a corrupt role is a loud ValueError
            // rather than a silently dropped row, because a membership list that drops a real
            // row under-reports membership and contradicts §6.1's row-counting member_count.
            // This column's case is narrower than that one, because plan_invites.role has one
            // intended writer rather than arbitrary ones, so this task adds no corrupt-role test
            // of its own for this column; Task 9's test already pins the ruling for this enum.
            role: MemberRole::from($invite->role),
            expiresAt: $invite->expires_at,
            acceptedAt: $invite->accepted_at,
            revokedAt: $invite->revoked_at,
            invitedByName: $invitedByName,
        );
    }
}
