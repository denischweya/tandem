<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanPermissions;
use App\Domain\Plans\PlanStatus;
use App\Models\PlanMember;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use RuntimeException;

final readonly class ChangeMemberRole
{
    public function __construct(
        private PlanRepository $plans,
        private PlanMemberRepository $members,
        private RecordActivity $activity,
    ) {}

    /**
     * Owner only (spec §8.2), because `assign admins` appears under Owner and nowhere under
     * Admin.
     *
     * Every throw below happens before the first write, which is inside one of the two
     * transactions at the end - the discipline ResetPassword's docblock states and
     * RefreshSession's defect taught: DB::transaction() rolls back on any Throwable, so a throw
     * positioned after a write would undo it.
     *
     * The state check runs BEFORE the role check here, which is the opposite of UpdatePlan's
     * order, and the asymmetry is disclosed rather than defended: UpdatePlan authorizes first so
     * that "a caller who may never edit gets the same answer whatever the Plan's lifecycle is
     * doing", while this method cannot authorize first at all - canChangeRole() takes the
     * TARGET's role, so the target has to be resolved first, and resolving a {user} on a Plan
     * that is already read-only is work with no possible outcome. The consequence is that an
     * admin asking for a role change on an archived Plan is told 409 here and would be told 403
     * by the same actor's PATCH /plans/{plan}. Nothing in spec §5.3 or §8.2 picks between the
     * two, and NO TEST IN ChangeMemberRoleTest DISTINGUISHES THEM - every archived case in it
     * has the owner as the actor, for whom both orders answer 409. Worth a ruling if the two
     * routes are ever asked to agree.
     *
     * @throws PlanNotVisible the actor cannot see this Plan (spec §5.1)
     * @throws PlanNotEditable the Plan is not active (spec §5.3)
     * @throws PlanMemberNotFound {user} is not an active member of it (spec §5.2)
     * @throws AuthorizationException the actor's role does not permit this change (spec §8.2)
     */
    public function execute(string $actorId, string $planId, string $targetUserId, MemberRole $to): PlanMemberView
    {
        $context = $this->plans->findVisibleTo($planId, $actorId);

        // "Anything but active", not "archived" specifically - the predicate PlanNotEditable's
        // own docblock commits every caller of it to, so that this route and PATCH /plans/{plan}
        // cannot disagree about whether a cancelled Plan is editable. Nothing in Wave 2 writes
        // PlanStatus::Cancelled, so the two readings answer identically today.
        if ($context->plan->status !== PlanStatus::Active->value) {
            throw new PlanNotEditable;
        }

        // The Owner branch resolves the target through findTransferTarget(), which adds
        // DeleteAccount::successorFor()'s account filter - the target's account neither
        // soft-deleted nor suspended - to findActiveMember()'s conditions. Spec §9.3: if the
        // transfer endpoint omits that filter, the two paths that move ownership disagree, and a
        // failure mode DeleteAccount treats as a bug becomes reachable by a deliberate,
        // successful request. An ineligible target answers 404, identically to a {user} who is
        // not a member, because "that person's account is suspended" is not something a Plan
        // owner needs told (blueprint §126).
        //
        // The filter is on the Owner arm only, deliberately: a suspended admin is still a member
        // for every other purpose, so demoting them must keep working. "lets the owner demote an
        // ineligible member even though it cannot promote them" is the test that stops this being
        // over-applied to every role change.
        $target = $to === MemberRole::Owner
            ? $this->members->findTransferTarget($planId, $targetUserId)
            : $this->members->findActiveMember($planId, $targetUserId);

        // The target is resolved BEFORE the authorization check, and the order is forced rather
        // than chosen: canChangeRole() takes the TARGET's current role, so there is no order in
        // which the role check could run first. It discloses nothing - everyone who reaches this
        // line is an active member, and spec §8.1 already gives every active member the whole
        // member list. The consequence a test pins: an Admin actor naming a {user} who is not a
        // member gets 404, not 403.
        $targetRole = MemberRole::tryFrom($target->role);

        if ($targetRole === null) {
            // Fail closed. plan_members.role carries no CHECK of any kind, so a value no
            // MemberRole case recognises is a row this application cannot reason about - and for
            // every purpose this endpoint has, that is not a membership. 404 rather than the 500
            // a MemberRole::from() would raise.
            throw new PlanMemberNotFound;
        }

        if (! PlanPermissions::canChangeRole($context->role, $targetRole, $to)) {
            throw new AuthorizationException;
        }

        // Baseline S-37's invariant, checked here because this is the last point before the
        // first write: status = 'active' implies joined_at IS NOT NULL. No constraint can express
        // it under the migration freeze, PlanMemberFactory's default is 'joined_at' => null, and
        // the 200 below publishes a non-nullable joined_at.
        //
        // It narrows nothing for the analyser - this method never reads joined_at, the repository
        // does - so what holds this check open is one named test, and specifically its "nothing
        // was written" assertion rather than its status: PlanMemberView::fromMember() would raise
        // its own RuntimeException for the same row a moment later, so the 500 arrives either
        // way. Deleting this check costs a committed role change and a committed activity row on
        // a Plan whose response was a 500, which is what that assertion sees and the status
        // cannot.
        if ($target->joined_at === null) {
            throw new RuntimeException(
                "plan_members row {$target->id} is active with no joined_at, which breaks the "
                .'status = active implies joined_at IS NOT NULL invariant the members list depends on.'
            );
        }

        // AFTER the authorization check, deliberately. Spec §8.2 lists "a change that is already
        // true" among the four things canChangeRole refuses and then says it is "accepted as a
        // 200 with no write and no activity row"; those cannot both hold, because a false from
        // canChangeRole renders as 403. Idempotence is this method's short-circuit, not an
        // authorization answer - so a Member actor asking for a no-op still gets 403, and so does
        // an owner naming their own row with role: owner. Two cases in ChangeMemberRoleTest
        // observe this ordering rather than one: "refuses a member actor with 403, even for a
        // change that is already true", and the third iteration of "refuses the owner as the
        // target with 403, whatever role is asked for" - the second being the stronger, because a
        // no-op on the owner's own row is also the shape that would let an owner self-demote.
        if ($targetRole === $to) {
            return $this->members->findActiveMemberView($planId, $target->user_id);
        }

        if ($to === MemberRole::Owner) {
            return $this->transfer($context, $actorId, $planId, $target, $targetRole);
        }

        DB::transaction(function () use ($actorId, $planId, $target, $targetRole, $to): void {
            $target->forceFill(['role' => $to->value])->save();

            // Inside the transaction, so a role change that did not commit has no activity row
            // and the feed can never describe something that did not happen (spec §11.2).
            $this->activity->execute($planId, $actorId, ActivityType::MemberRoleChanged, RecordActivity::SUBJECT_USER, $target->user_id, [
                'from' => $targetRole->value,
                'to' => $to->value,
            ]);
        });

        return $this->members->findActiveMemberView($planId, $target->user_id);
    }

    /**
     * Spec §9.3's ownership transfer: four writes, all four together or none.
     *
     * There is no separate transfer route, and that is the decision rather than a shortcut: spec
     * §8's route list is the published surface, `role` is already the field this route changes,
     * and spec §9.3 already performs this exact transition automatically when an owner deletes
     * their account - so the question was never whether the transition is legitimate, only
     * whether a user may ask for it deliberately.
     */
    private function transfer(PlanContext $context, string $actorId, string $planId, PlanMember $target, MemberRole $targetRole): PlanMemberView
    {
        // Spec §8.1: ownership is answered from plans.owner_id, never from plan_members.role,
        // because plans.owner_id is NOT NULL with RESTRICT while plan_members.role has no
        // constraint - nothing in the database prevents zero or two owner rows. canChangeRole()
        // took the role because spec §4.1 pins that signature, so this is the one place the
        // constrained source is consulted. A disagreement is spec §9.1's invariant already
        // broken, which no write in this application can produce: the transaction below writes
        // both in one go and DeleteAccount::resolvePlanOwnership() does the same.
        if ($context->plan->owner_id !== $actorId) {
            throw new RuntimeException(
                "Plan {$planId} has an active plan_members row with role = owner for user {$actorId} "
                .'while plans.owner_id names someone else, which is spec §9.1 one-owner invariant broken.'
            );
        }

        $actorMember = $this->members->findActiveMember($planId, $actorId);

        DB::transaction(function () use ($actorId, $planId, $context, $target, $targetRole, $actorMember): void {
            // 1. plans.owner_id, the constrained source of truth.
            $context->plan->forceFill(['owner_id' => $target->user_id])->save();

            // 2. the target's row, so the two sources agree.
            $target->forceFill(['role' => MemberRole::Owner->value])->save();

            // 3. the outgoing owner, demoted - not left as owner, because spec §9.1's invariant
            // permits exactly one. To admin rather than member, and that is a decision rather
            // than a default: the outgoing owner was the Plan's administrator a moment ago, and
            // silently stripping them of invite members and remove members in the same request
            // is a second decision the user did not make. They can be demoted further afterwards
            // by the new owner.
            $actorMember->forceFill(['role' => MemberRole::Admin->value])->save();

            // 4. one row per member, both inside this transaction.
            $this->activity->execute($planId, $actorId, ActivityType::MemberRoleChanged, RecordActivity::SUBJECT_USER, $target->user_id, [
                'from' => $targetRole->value,
                'to' => MemberRole::Owner->value,
            ]);

            $this->activity->execute($planId, $actorId, ActivityType::MemberRoleChanged, RecordActivity::SUBJECT_USER, $actorId, [
                'from' => MemberRole::Owner->value,
                'to' => MemberRole::Admin->value,
            ]);
        });

        return $this->members->findActiveMemberView($planId, $target->user_id);
    }
}
