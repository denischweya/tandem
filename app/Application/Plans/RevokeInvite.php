<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\Capability;
use App\Domain\Plans\PlanPermissions;
use App\Domain\Plans\PlanStatus;
use App\Domain\Shared\Clock;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class RevokeInvite
{
    public function __construct(
        private PlanRepository $plans,
        private InviteRepository $invites,
        private RecordActivity $activity,
        private Clock $clock,
    ) {}

    /**
     * EVERY THROW HAPPENS BEFORE THE FIRST WRITE, and all four of them sit outside the
     * transaction because every one of them is a read. That is the discipline ResetPassword's
     * docblock states: DB::transaction() rolls back on any Throwable, so a throw positioned
     * after a write would undo it.
     *
     * The early RETURN is in the same category and is deliberately also before the transaction:
     * an invite that is already accepted or already revoked is a 204 that writes nothing, so
     * there is no transaction to open.
     *
     * @throws PlanNotVisible
     * @throws AuthorizationException
     * @throws PlanNotEditable
     * @throws PlanInviteNotFound
     */
    public function execute(User $actor, string $planId, string $inviteId): void
    {
        $context = $this->plans->findVisibleTo($planId, $actor->id);

        // 403, not 404: this member can plainly see the Plan. Revoking takes the same
        // capability as inviting, because revoking is how an invite is withdrawn by the people
        // who could have issued it, and the owner holds it too through the additive role
        // hierarchy.
        if (! PlanPermissions::allows($context->role, Capability::InviteMembers)) {
            throw new AuthorizationException;
        }

        // Compared against PlanStatus::Active->value rather than cast: plans.status is a plain
        // varchar with no enum cast and no CHECK constraint, and a cast turns any unrecognised
        // stored value into a ValueError during hydration. 409 because the refusal is about the
        // PLAN'S STATE, not about who is asking - and GET /plans/{plan}/invites deliberately
        // has no such check, because reading is not writing.
        if ($context->plan->status !== PlanStatus::Active->value) {
            throw new PlanNotEditable;
        }

        // Scoped to the Plan inside the repository, so an {invite} belonging to another Plan
        // is PlanInviteNotFound rather than a successful revoke.
        $invite = $this->invites->findForPlan($context->plan->id, $inviteId);

        // revoked_at is set ONLY when both accepted_at and revoked_at are null; otherwise this
        // writes nothing and still answers 204. An already-revoked invite must keep its
        // original timestamp - re-stamping it moves the moment the invite died - and an
        // already-accepted one must not be made to say it was both used and cancelled.
        if ($invite->accepted_at !== null || $invite->revoked_at !== null) {
            return;
        }

        DB::transaction(function () use ($actor, $context, $invite): void {
            // forceFill, because nothing on PlanInvite is mass-assignable. revoked_at comes
            // from Clock, not now(). The row is UPDATED and never deleted - the migration's own
            // reason for nullOnDelete on invited_by/accepted_by is the "who invited whom" trail,
            // and deleting the row would destroy the same trail from the other end.
            $invite->forceFill(['revoked_at' => $this->clock->now()])->save();

            // Inside the transaction, after the write it describes, so an invite that was not
            // revoked has no "revoked" row. InviteRevoked carries the invite as its subject and
            // no metadata at all; RecordActivity matches this argument against its own per-type
            // table and throws InvalidArgumentException - an uncaught 500 - on a disagreement.
            $this->activity->execute(
                $context->plan->id,
                $actor->id,
                ActivityType::InviteRevoked,
                RecordActivity::SUBJECT_INVITE,
                $invite->id,
                [],
            );
        });
    }
}
