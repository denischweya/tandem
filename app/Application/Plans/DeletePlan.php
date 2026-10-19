<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\Capability;
use App\Domain\Plans\PlanPermissions;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class DeletePlan
{
    public function __construct(private PlanRepository $plans) {}

    /**
     * Owner only. Admin gets 403, which is the matrix's clearest line: Admin has `edit Plan` and
     * not `delete Plan`.
     *
     * No PlanNotEditable check: deleting an archived Plan is allowed (§5.3's Allowed column),
     * because it is the owner's natural cleanup and refusing it would leave a Plan nobody can
     * remove.
     *
     * No transaction, because this is one statement: plan_members, plan_invites and the activity
     * table all declare CASCADE on plans in the migration, so Postgres removes them.
     * It is a HARD delete, and Plan must never gain SoftDeletes - PurgeDeletedUsersCommand's
     * comment records that "if a later task adds SoftDeletes to Plan, this line must become
     * forceDelete() or the purge silently stops working while still reporting success", with a
     * named test that fails with a Postgres FK violation on plans_owner_id_foreign if the
     * statement stops removing rows. plans has no deleted_at column, which is the schema-level
     * half of the same constraint.
     *
     * @throws PlanNotVisible
     * @throws AuthorizationException
     */
    public function execute(User $actor, string $planId): void
    {
        $context = $this->plans->findVisibleTo($planId, $actor->id);

        if (! PlanPermissions::allows($context->role, Capability::DeletePlan)) {
            throw new AuthorizationException;
        }

        $context->plan->delete();
    }
}
