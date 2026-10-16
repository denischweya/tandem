<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\PlanStatus;
use App\Models\Plan;

/**
 * Spec §4.3. This interface is in Application and not in app/Domain/Plans, and the reason is
 * mechanical rather than stylistic: findById() returns ?App\Models\Plan and findVisibleTo()
 * returns a PlanContext that holds one, and the arch rule
 * toOnlyUse(['App\Domain', 'DateTimeImmutable', 'DateTimeZone', 'InvalidArgumentException'])
 * admits neither App\Models nor App\Application. The implementation is in
 * App\Infrastructure\Persistence\Eloquent and is bound in AppServiceProvider::register(),
 * following the one interface-to-implementation precedent this codebase has, Clock/SystemClock.
 *
 * Reads only. Every Wave 2 write assigns field by field inside the use case's own
 * DB::transaction(), because §10.7's UniqueConstraintViolationException catch has to sit around
 * that transaction - "the closure already rolls back on any Throwable before this catch ever
 * runs" - and a repository that owned the write would split one transaction across two layers.
 */
interface PlanRepository
{
    /**
     * The first statement of every Plan use case, which is what makes §5's 404 rule enforced
     * once rather than per controller.
     *
     * It deliberately applies NO plans.status filter: §5.3 keeps every GET on an archived Plan
     * working and answers the write paths with PlanNotEditable (409), which is raised after
     * visibility has already succeeded - so narrowing this method to `active` would turn every
     * archived-Plan 409 into a 404 and make that 409 unreachable. listForActor() below filters
     * plans.status because choosing which Plans to list is a different question from whether
     * the actor may see one. PlanRepositoryTest's "keeps an archived Plan visible to its active
     * member" is the case that reds if this is ever tightened.
     *
     * @throws PlanNotVisible for a malformed $planId, an unknown Plan, an actor with no
     *                        plan_members row, or an actor whose row's status does not satisfy
     *                        PlanPermissions::grantsAccess()
     */
    public function findVisibleTo(string $planId, string $actorId): PlanContext;

    /**
     * §7.1: the Plans the actor is an `active` member of, filtered on plans.status, ordered
     * plans.updated_at DESC, plans.id DESC (§6.3). There is no 403 and no 404 here - the result
     * set is the authorization, and a user with no Plans gets an empty page.
     *
     * $query->limit is clamped to PageQuery::MAX_LIMIT. $query->cursor is always either null or
     * a PageCursor this API itself issued - a value it did not issue is a 422 before this method
     * is ever called (the resources section's ValidCursor rule).
     *
     * @return CursorPage<PlanContext>
     */
    public function listForActor(string $actorId, PlanStatus $status, PageQuery $query): CursorPage;

    /**
     * No membership check and no exception, because the two callers have no membership to
     * check: §10.3's unauthenticated invite preview and §10.7 step 3's archived-Plan refusal,
     * where the invite token is the credential and the caller is by definition not a member
     * yet. Null for a malformed or unknown id.
     */
    public function findById(string $planId): ?Plan;

    /** §6.1's member_count for a Plan the caller cannot see yet - §10.3's preview. 0 for a malformed id. */
    public function countActiveMembers(string $planId): int;
}
