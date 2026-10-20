<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\PlanStatus;
use App\Models\User;

final readonly class ListPlans
{
    public function __construct(private PlanRepository $plans) {}

    /**
     * There is no authorization check in this method, and that is the design rather than an
     * omission: the result set IS the authorization. The repository selects through the
     * actor's own membership rows with an active status, so a Plan the actor is not an active
     * member of is absent rather than refused - which is why this route has no 403 and no 404
     * at all, and why a user with no Plans gets an empty data array instead of an error.
     *
     * $query carries limit and cursor together, already validated: by the time this method
     * runs, ListPlansRequest's `cursor` rule (new ValidCursor) has already refused a malformed
     * value with a 422, so $query->cursor is either null or a PageCursor this repository can
     * trust.
     *
     * @return CursorPage<PlanContext>
     */
    public function execute(User $actor, PlanStatus $status, PageQuery $query): CursorPage
    {
        return $this->plans->listForActor($actor->id, $status, $query);
    }
}
