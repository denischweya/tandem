<?php

declare(strict_types=1);

namespace App\Application\Plans;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class ListPlanMessages
{
    public function __construct(
        private PlanRepository $plans,
        private PlanMessageRepository $messages,
    ) {}

    /**
     * Same shape as SendMessage: findVisibleTo() is called only for its throw, and the plan
     * and actor ids already in hand travel straight to the repository. No further
     * PlanPermissions::allows() check, deliberately - the membership resolution IS the
     * authorization for a read any active member may make, the same reasoning
     * ListPlanActivity's own docblock gives for its sibling feed.
     *
     * @return CursorPage<PlanMessageSummary>
     */
    public function execute(Authenticatable $actor, string $planId, PageQuery $query): CursorPage
    {
        $this->plans->findVisibleTo($planId, $actor->getAuthIdentifier());

        return $this->messages->listForPlan($planId, $actor->getAuthIdentifier(), $query);
    }
}
