<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Models\PlanInvite;

/**
 * Spec §4.3. In Application and not Domain because every method names App\Models\PlanInvite.
 * Reads only: §10.4's mint, §10.7's claim and §10.8's revoke all assign fields inside the use
 * case's own transaction, and §10.7's UniqueConstraintViolationException catch has to sit
 * around that transaction.
 *
 * The two token lookups take the PLAINTEXT token, not a hash: both callers (§10.3's preview and
 * §10.7 step 1's claim) hold nothing but the plaintext straight off the request, and
 * hash('sha256', $plainToken) runs inside this class rather than at each call site - unlike
 * ResetPassword::execute()'s PasswordResetToken lookup, which hashes before calling.
 * plan_invites.token_hash is UNIQUE, so both lookups still hit a unique index.
 */
interface InviteRepository
{
    /**
     * §10.8's {invite}, scoped to the Plan it was addressed through.
     *
     * Accepted and revoked rows are findable, because §10.8 answers a revoke of an
     * already-accepted invite with a silent 204 and the use case can only do that if the row
     * comes back.
     *
     * @throws PlanInviteNotFound for a malformed $planId or $inviteId, an unknown invite, or an
     *                            invite belonging to a different Plan
     */
    public function findForPlan(string $planId, string $inviteId): PlanInvite;

    /**
     * §10.3's preview: no lock, because it writes nothing and the caller is unauthenticated.
     * Takes the plaintext token and hashes it internally - see the interface docblock.
     */
    public function findByToken(string $plainToken): ?PlanInvite;

    /**
     * §10.7 step 1: the same lookup under lockForUpdate(), because two devices accepting one
     * single-use invite concurrently is a race that has to resolve deterministically. This is
     * ResetPassword's precedent, and it is only meaningful inside the caller's transaction.
     */
    public function findByTokenForUpdate(string $plainToken): ?PlanInvite;

    /**
     * §10.8's list: every invite of the Plan, including accepted and revoked ones, ordered
     * created_at DESC, id DESC (§6.3).
     *
     * NOT paginated, and §6.3 records the asymmetry deliberately: "the outstanding-invite list
     * for one Plan is bounded by the 20/hour/plan limit and by a 14-day TTL; a Plan with enough
     * live invites to need a page is a Plan being abused, which is the rate limit's problem".
     * Empty for a malformed id.
     *
     * @return list<PlanInviteView>
     */
    public function listForPlan(string $planId): array;
}
