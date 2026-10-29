<?php

declare(strict_types=1);

namespace App\Application\Plans;

interface PlanMessageRepository
{
    public function create(string $planId, string $userId, string $body): PlanMessageSummary;

    /**
     * $viewerId now does real work: Task 4 populates PlanMessageSummary::$reactions from
     * plan_message_reactions, batched over the page's own message ids rather than one query
     * per row, and reactedByViewer compares each (message, reaction) group's user-id set
     * against $viewerId.
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
     *
     * The returned summary's reactions carry real reaction and count values (the same
     * batched query listForPlan() uses, sized to one row) - but reactedByViewer is always
     * false, because this method takes no viewer. That is inert rather than wrong: no caller
     * of find() ever serialises its result to a client. AddReaction and RemoveReaction call it
     * only for the same existence/plan-membership check EditMessage and DeleteMessage already
     * make, and EditMessage's own HTTP response comes from update()'s summary, not find()'s.
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

    /**
     * Adds $reaction as $userId's own reaction to $messageId. Idempotent by construction, not
     * by pre-check: a second call with the same (messageId, userId, reaction) triple hits the
     * table's own UNIQUE(message_id, user_id, reaction) constraint, which the implementation
     * catches and swallows rather than avoiding with a race-prone exists() query first.
     * $reaction is one of the five emoji AddReactionRequest's rule admits - this method does
     * not re-validate it.
     */
    public function addReaction(string $messageId, string $userId, string $reaction): void;

    /**
     * Removes $userId's own $reaction from $messageId. A plain delete by the matching triple -
     * deleting zero rows (no such reaction, already removed, or an unknown $messageId) is also
     * a no-op success, the same reasoning the interface docblock above gives for addReaction():
     * there is nothing a caller could have done differently and nothing to report.
     */
    public function removeReaction(string $messageId, string $userId, string $reaction): void;
}
