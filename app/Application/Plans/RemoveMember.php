<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanPermissions;
use App\Domain\Plans\PlanStatus;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class RemoveMember
{
    public function __construct(
        private PlanRepository $plans,
        private PlanMemberRepository $members,
        private RecordActivity $activity,
    ) {}

    /**
     * Spec §3.3: Owner removes an Admin or a Member; Admin removes a Member only; nobody
     * removes the Owner. Member actor → 403. Non-member actor → 404. {user} not an active
     * member → 404. Archived Plan → 409.
     *
     * Takes the User and not just an id, unlike every other use case in this directory, because
     * of the first check below. DeleteAccount::execute(User $user) is the precedent. Every
     * throw happens before the write in the closure at the end.
     *
     * @throws ActorEmailNotVerified the actor has not verified their email address (§7.5)
     * @throws PlanNotVisible the actor cannot see this Plan (§5.1)
     * @throws PlanNotEditable the Plan is not active (§5.3)
     * @throws PlanMemberNotFound {user} is not an active member of it (§5.2)
     * @throws AuthorizationException the role matrix forbids this removal (§3.3)
     */
    public function execute(User $actor, string $planId, string $targetUserId): void
    {
        // FIRST, before findVisibleTo(), and the position is the decision. Spec §7.5 gates
        // "removing another member" on `verified`, and on every other gated route the gate is
        // EnsureEmailIsVerified, which runs before the controller - so checking it first is what
        // makes this route's refusal ORDER the same as theirs, and an unverified non-member gets
        // the same 403 here that they would get there. The route itself cannot carry the
        // middleware: DELETE .../members/{user} is also LeavePlan, which §7.5 deliberately does
        // not gate (§8.3), and middleware cannot see which of the two a request is.
        //
        // This check is the ONE §7.5 verdict that VerifiedGateTest cannot hold open: that gate
        // reads `verified` off the real route table, this route correctly carries none, so its
        // row is a disclosed false negative and stays green whether or not this line exists.
        // What holds it open is LeaveAndRemoveMemberTest's "refuses an unverified actor removing
        // another member, before it looks the Plan up", whose second request - against a Plan
        // this actor cannot see at all - is what pins the POSITION rather than the existence.
        if (! $actor->hasVerifiedEmail()) {
            throw new ActorEmailNotVerified;
        }

        $context = $this->plans->findVisibleTo($planId, $actor->id);

        // Spec §5.3, and before the {user} lookup, matching §5's table: PlanNotEditable is
        // raised by every write path, after visibility. An archived Plan may have no authorized
        // administrator at all - a Plan archived by §9.3 branch 3 has no active owner row -
        // which is why the refusal is on the Plan's state and not on the caller's identity.
        //
        // "Anything but active", not "archived" specifically: the predicate PlanNotEditable's
        // own docblock commits every caller to, so this route and PATCH .../members/{user}
        // cannot disagree about whether a cancelled Plan is editable. Nothing in Wave 2 writes
        // PlanStatus::Cancelled, so the two readings answer identically today.
        if ($context->plan->status !== PlanStatus::Active->value) {
            throw new PlanNotEditable;
        }

        $target = $this->members->findActiveMember($planId, $targetUserId);

        $targetRole = MemberRole::tryFrom($target->role);

        if ($targetRole === null) {
            // Fail closed: plan_members.role carries no CHECK, so a value no MemberRole case
            // recognises is a row this application cannot reason about, and for this endpoint's
            // purposes that is not a membership. 404, not the 500 a MemberRole::from() would
            // raise - and a corrupt role can never become an input to the matrix check below.
            //
            // What holds this branch open is one named test and nothing else:
            // LeaveAndRemoveMemberTest's "answers 404 rather than 500 for a member whose role no
            // MemberRole case recognises". Measured by swapping tryFrom() for from(): that case
            // reds on 500, and the rest of the suite stays green. The branch is unreachable
            // through any route - no write in this application stores a role outside the enum -
            // so that test manufactures the row with forceFill(), which is the only way to get
            // one past PlanMember's $guarded = ['*'].
            throw new PlanMemberNotFound;
        }

        // Spec §3.3's decision, with the rationale recorded there: the owner appointed the admin
        // (§3.2 makes that owner-only), so letting a second admin undo that appointment hands
        // admins a one-way power over each other that the matrix reserves to the owner under
        // `manage members` - and two admins who fall out could otherwise remove each other,
        // whichever request lands first winning, an outcome nobody designed.
        //
        // "Nobody removes the Owner" is §9.1's invariant stated from the other side, and it
        // holds for every actor including the owner themselves: canRemoveMember answers false
        // for every actor-equals-target pair, which is what makes the controller's
        // case-insensitive self/other dispatch a usability guard rather than a security one.
        if (! PlanPermissions::canRemoveMember($context->role, $targetRole)) {
            throw new AuthorizationException;
        }

        DB::transaction(function () use ($actor, $planId, $target): void {
            // status AND role, unlike LeavePlan. Spec §8.3: the demotion is there for
            // DeleteAccount's reason, applied to the second path that can produce a stale role -
            // a removed admin whose row still says admin is a row claiming authority its status
            // denies, and §9.1's invariant is easier to state and to test if role is only ever
            // meaningful on an active row.
            //
            // The row is UPDATED, never deleted: UNIQUE(plan_id, user_id) makes it the only
            // place a re-invite can land (§10.7 step 7), and deleting it would erase the fact
            // that a removal happened. Nothing about the response or about any authorization
            // rule in this wave can see the difference - a deleted row refuses access exactly as
            // a `removed` one does - so one counted assertion in
            // LeaveAndRemoveMemberTest is the whole guard.
            $target->forceFill([
                'status' => MemberStatus::Removed->value,
                'role' => MemberRole::Member->value,
            ])->save();

            $this->activity->execute($planId, $actor->id, ActivityType::MemberRemoved, RecordActivity::SUBJECT_USER, $target->user_id, []);
        });
    }
}
