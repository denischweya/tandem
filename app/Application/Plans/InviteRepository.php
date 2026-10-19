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
 * All three token lookups take the PLAINTEXT token, not a hash: every caller (§10.3's preview,
 * §10.7 step 1's claim, and findPreviewByToken() below) holds nothing but the plaintext
 * straight off the request, and hash('sha256', $plainToken) runs inside this class rather than
 * at each call site - unlike ResetPassword::execute()'s PasswordResetToken lookup, which hashes
 * before calling. plan_invites.token_hash is UNIQUE, so every lookup still hits a unique index.
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
     * §10.3's preview: the one lookup an unauthenticated caller reaches, before proving
     * anything. Returns the blueprint §126-shaped minimum rather than the PlanInvite model -
     * the Plan's title, the inviter's display name, and a count - not the model this
     * interface's other lookups return, because that model carries token_hash and the
     * invitee's own email, neither of which is the unauthenticated clicker's business.
     *
     * Does not check expiry, revocation or acceptance - that is InvitePolicy::decide()'s job,
     * one layer up. Spec §10.3 shows a preview for an expired or already-accepted invite too,
     * with a different message, not a 404; mixing that decision in here would duplicate the
     * policy.
     *
     * The active-member count inside InvitePreview is computed by this method's own query,
     * independently of the Plan repository's own active-member count, rather than delegated to
     * it - the two filter identically today (plan id, status = active), so there is no bug, but
     * nothing links them: a future ruling about a suspended or otherwise-unreachable member
     * would need to be applied in both places, and this is the one an unauthenticated caller
     * can see.
     */
    public function findPreviewByToken(string $plainToken): ?InvitePreview;

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
