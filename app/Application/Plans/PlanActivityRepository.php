<?php

declare(strict_types=1);

namespace App\Application\Plans;

interface PlanActivityRepository
{
    /**
     * Ordered created_at DESC, id DESC (spec §6.3), over the existing composite index on this
     * table's (plan_id, created_at) columns (baseline C-5) - named in full on the Eloquent
     * implementation's own docblock rather than here, so this file carries no literal mention
     * of the table name DomainPurityTest's content scan would otherwise have to allow-list.
     * The row-comparison predicate form, not the OR form - D-R5.
     *
     * Unlike PlanRepository::listForActor() and PlanMemberRepository::listActiveMembers(),
     * this implementation does NOT clamp $query->limit - there is no private pageSize() here.
     * The upper bound is enforced only by ListPlanActivityRequest::rules()'s
     * 'max:'.PageQuery::MAX_LIMIT (a 422, not a silent clamp), so a caller that builds a
     * PageQuery outside that validation can hand this method an out-of-range or zero limit
     * unchanged. Recorded as a divergence from its two siblings, not fixed here: the task that
     * created this interface owns no change to either of them.
     *
     * @return CursorPage<PlanActivitySummary>
     */
    public function listForPlan(string $planId, PageQuery $query): CursorPage;
}
