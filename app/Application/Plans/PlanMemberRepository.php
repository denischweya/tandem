<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Models\PlanMember;

/**
 * Spec §4.3. In Application and not Domain because every method names App\Models\PlanMember.
 * Reads only - §8.2's and §8.3's writes assign fields inside the use case's own transaction.
 *
 * Every method here runs AFTER PlanRepository::findVisibleTo(), and §5.2 makes that ordering
 * load-bearing rather than conventional: "a use case that resolved the member before the Plan
 * would turn a precise message into a Plan-existence oracle".
 */
interface PlanMemberRepository
{
    /**
     * §8.2 and §8.3's {user}: an `active` member of this Plan, and nothing else.
     *
     * @throws PlanMemberNotFound for a malformed $planId or $userId, an unknown user, a user
     *                            who is not a member of this Plan, or a member whose status is
     *                            not `active`
     */
    public function findActiveMember(string $planId, string $userId): PlanMember;

    /**
     * §9.3's transfer target: findActiveMember()'s conditions plus
     * DeleteAccount::successorFor()'s account filter - the user neither soft-deleted nor
     * suspended. The two paths that move ownership have to agree, or a failure mode
     * DeleteAccount treats as a bug becomes reachable by a deliberate, successful request.
     *
     * @throws PlanMemberNotFound for every ineligible target, with no distinction between them
     */
    public function findTransferTarget(string $planId, string $userId): PlanMember;

    /**
     * Any status, including `left` and `removed`, and null rather than an exception.
     *
     * §10.7 step 7's upsert is the caller: UNIQUE(plan_id, user_id) plus permanently-retained
     * left/removed rows means anyone who previously left or was removed already has a row, and
     * spec §9.3 guarantees such rows for every user who has ever deleted an account. Null for
     * a malformed id.
     */
    public function findMembership(string $planId, string $userId): ?PlanMember;

    /**
     * §8.1: the Plan's `active` members, ordered joined_at ASC, id ASC (§6.3), each with the
     * display_name §6.1 publishes. Rows whose joined_at is NULL are excluded - see the
     * implementation for why that is a correctness decision and not a tidy-up.
     *
     * An empty page is a legitimate answer for a Plan that really exists, and a caller must not
     * read one as "no such Plan". §7.4's account-deletion path archives a successor-less Plan
     * and marks every membership of the departing user `left` with no plan filter, so a sole
     * owner deleting their account leaves a persisted Plan whose every membership row is
     * `left` until the day-30 purge hard-deletes it.
     *
     * A malformed $planId is an EMPTY PAGE, not an exception and not null - a third answer for
     * the same input, and deliberately different from the two above. There is no 404 on a list:
     * the result set is the answer, so there is nothing for a refusal to say that an empty page
     * does not already say. §5.1's shape check still has to happen, because without it
     * `where plan_id = 'not-a-uuid'` against a uuid column is Postgres 22P02 and a 500.
     *
     * $query->limit is clamped to PageQuery::MAX_LIMIT. $query->cursor is always either null or
     * a PageCursor this API itself issued - a value it did not issue is a 422 before this method
     * is ever called (the resources section's ValidCursor rule), the same guarantee
     * PlanRepository::listForActor() documents.
     *
     * @return CursorPage<PlanMemberView>
     */
    public function listActiveMembers(string $planId, PageQuery $query): CursorPage;
}
