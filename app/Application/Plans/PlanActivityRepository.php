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
     * $query->limit is clamped to PageQuery::MAX_LIMIT, matching both sibling interfaces: an
     * unclamped limit = 0 - reachable unvalidated, since a whitespace-only ?limit= skips every
     * non-implicit validation rule and (int) ' ' is 0 - would otherwise return an empty page
     * and a null cursor for a Plan that does have activity, the same defect
     * PlanMemberRepositoryTest's own clamp case pins for its sibling.
     *
     * Unlike all three sibling repository methods (PlanRepository::findVisibleTo() /
     * listForActor(), PlanMemberRepository's own *Query() helpers), this method carries no
     * Str::isUuid($planId) shape check. A malformed $planId reaches Postgres as 22P02, which
     * is not in bootstrap/app.php's exception mapping and renders as an unrendered 500. This is
     * deferred, not overlooked: the brief for this interface omits the guard too, and guard
     * reconciliation across the four repositories is Ruling A's, assigned to a later task with
     * an explicit instruction not to retro-fit it here. A caller must not assume this method
     * rejects a malformed id on its own.
     *
     * @return CursorPage<PlanActivitySummary>
     */
    public function listForPlan(string $planId, PageQuery $query): CursorPage;
}
