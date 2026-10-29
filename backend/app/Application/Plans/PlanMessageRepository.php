<?php

declare(strict_types=1);

namespace App\Application\Plans;

interface PlanMessageRepository
{
    public function create(string $planId, string $userId, string $body): PlanMessageSummary;

    /**
     * $viewerId is unused by every implementation until Task 4 populates
     * PlanMessageSummary::$reactions for real - it is part of the interface now so that
     * change needs no call-site update. Until then every returned summary carries reactions: [].
     *
     * @return CursorPage<PlanMessageSummary>
     */
    public function listForPlan(string $planId, string $viewerId, PageQuery $query): CursorPage;

    /**
     * The one row named by $messageId, by primary key alone - no plan scoping and no
     * authorship check. Null for a malformed id, an unknown one, or a soft-deleted one: a
     * caller editing or deleting a message that is already gone should see the same
     * PlanMessageNotFound a never-existed id gives, not resurrect it. EditMessage and
     * DeleteMessage are what compare the returned summary's planId against the route's
     * {plan} and its userId against the actor - this method's job is the row, not who may
     * touch it or which Plan the route named.
     */
    public function find(string $messageId): ?PlanMessageSummary;

    /**
     * Rewrites body and stamps edited_at = now(), keyed on $messageId alone - the caller
     * (EditMessage) has already resolved find() and checked authorship, so this method does
     * not repeat either check.
     */
    public function update(string $messageId, string $body): PlanMessageSummary;

    /**
     * Stamps deleted_at = now() rather than deleting the row - §9.3's anonymisation and every
     * other soft-delete in this codebase leave the row for audit, and listForPlan()'s own
     * `WHERE deleted_at IS NULL` is what drops it out of the list. Keyed on $messageId alone,
     * for the same reason update() is: the caller has already resolved find() and checked
     * authorship.
     */
    public function softDelete(string $messageId): void;
}
