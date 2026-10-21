<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Models\User;

final readonly class ListPlanActivity
{
    public function __construct(
        private PlanRepository $plans,
        private PlanActivityRepository $activity,
    ) {}

    /**
     * findVisibleTo() first, like every other Plan use case (spec §4.3). It throws
     * PlanNotVisible for a malformed {plan}, an unknown Plan, an actor with no plan_members row
     * and an actor whose row's status does not grantsAccess() - one exception, one 404 body, so
     * a non-member cannot tell "this Plan does not exist" from "you are not in it". Nothing
     * after it needs to repeat the check.
     *
     * There is deliberately no PlanPermissions::allows() call. Spec §11 grants the feed to any
     * active member and §3.6's matrix grants `view Plan` to all three roles, so there is no role
     * distinction to make here: the membership resolution IS the authorization, the same way it
     * is for GET /plans. A capability check would be a line that cannot fail, which is worse
     * than no line, because the next reader would trust it.
     *
     * The page itself comes from the repository, which owns the keyset predicate and the single
     * batched user_profiles lookup for actor_name. This use case reads $context->plan->id rather
     * than the $planId it was given, so the id used for the query is the one the repository
     * resolved and authorized.
     *
     * @return CursorPage<PlanActivitySummary>
     */
    public function execute(User $actor, string $planId, PageQuery $query): CursorPage
    {
        $context = $this->plans->findVisibleTo($planId, $actor->id);

        return $this->activity->listForPlan($context->plan->id, $query);
    }
}
