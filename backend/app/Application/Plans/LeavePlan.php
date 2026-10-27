<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanPermissions;
use Illuminate\Support\Facades\DB;

final readonly class LeavePlan
{
    public function __construct(
        private PlanRepository $plans,
        private PlanMemberRepository $members,
        private RecordActivity $activity,
    ) {}

    /**
     * Any `active` member may leave. The Owner may not (spec §9.2). Allowed on an archived
     * Plan, and not gated on `verified` (§7.5) - both of which are visible here as the ABSENCE
     * of a check, so both are stated: there is no PlanNotEditable branch in this method and no
     * hasVerifiedEmail() call, deliberately. RemoveMember has both.
     *
     * Every throw happens before the write in the closure at the end, which is the discipline
     * ResetPassword's docblock states and RefreshSession's defect taught: DB::transaction()
     * rolls back on any Throwable, so a throw positioned after a write would undo it.
     *
     * @throws PlanNotVisible the actor is not an active member of this Plan (spec §5.1),
     *                        which is also the answer to leaving the same Plan twice
     * @throws OwnerCannotLeave the actor is the Plan's owner (§9.2)
     * @throws PlanMemberNotFound unreachable through the route: findVisibleTo() has already
     *                            resolved this actor's active membership, so the row exists.
     *                            Declared because the repository can raise it.
     */
    public function execute(string $actorId, string $planId): void
    {
        $context = $this->plans->findVisibleTo($planId, $actorId);

        // The whole of the last-owner rule, and it is one argument because the schema makes it
        // one: an owner who left would strand plans.owner_id on a non-member, and that stranded
        // Plan is precisely PurgeDeletedUsersCommand's loud-failure branch - an active Plan
        // still owned by an overdue soft-deleted user, which RESTRICT turns into a per-user
        // failure that will not self-heal on its own, the same row still sitting there unpurged
        // at the next 03:00 run. This refusal is the only thing keeping that branch unreachable.
        if (! PlanPermissions::canLeave($context->role)) {
            throw new OwnerCannotLeave;
        }

        $member = $this->members->findActiveMember($planId, $actorId);

        DB::transaction(function () use ($actorId, $planId, $member): void {
            // status only. Spec §8.3: `role` is left as it is. RemoveMember demotes because a
            // removed admin whose row still says admin is a row claiming authority its status
            // denies; here the role someone held when they chose to go is part of what `left`
            // records, and no reader is misled because every reader of plan_members.role
            // filters on status first.
            //
            // forceFill() and not fill(): PlanMember is $guarded = ['*'], so a supplied
            // attribute through fill() is a MassAssignmentException.
            $member->forceFill(['status' => MemberStatus::Left->value])->save();

            // Inside the transaction (spec §11.2), so a departure that did not commit leaves no
            // row in a feed every member reads.
            $this->activity->execute($planId, $actorId, ActivityType::MemberLeft, RecordActivity::SUBJECT_USER, $actorId, []);
        });
    }
}
