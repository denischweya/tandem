<?php

declare(strict_types=1);

namespace App\Application\Plans;

final readonly class ListPlanMembers
{
    public function __construct(
        private PlanRepository $plans,
        private PlanMemberRepository $members,
    ) {}

    /**
     * findVisibleTo() first, and the ordering is §5.2's load-bearing one rather than a
     * convention: it is the only thing that makes a non-member's 404 indistinguishable from
     * an unknown Plan's. Its return value is deliberately unused here - the call is the
     * authorization, and §8.1 gives this route to Owner, Admin and Member alike, so there is
     * no role predicate to consult afterwards. What refuses an invited, left or removed row
     * is grantsAccess() inside findVisibleTo() (§3.5).
     *
     * $query is a PageQuery built by the controller from ReadsPageQuery::pageQuery(), already
     * validated and already carrying a decoded PageCursor or null - not the (int, ?string)
     * pair an earlier shape of this plan described, which the resources section's pagination
     * ruling (R4/R7) superseded before this task ran.
     *
     * @return CursorPage<PlanMemberView>
     */
    public function execute(string $actorId, string $planId, PageQuery $query): CursorPage
    {
        $this->plans->findVisibleTo($planId, $actorId);

        return $this->members->listActiveMembers($planId, $query);
    }
}
