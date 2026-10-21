<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\Capability;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanPermissions;
use App\Domain\Plans\PlanStatus;
use App\Domain\Plans\Visibility;
use App\Domain\Users\Email;
use App\Infrastructure\Auth\PlanInviteTokens;
use App\Infrastructure\Outbox\OutboxWriter;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final readonly class InvitePlanMember
{
    public function __construct(
        private PlanRepository $plans,
        private PlanInviteTokens $tokens,
        private RecordActivity $activity,
        private OutboxWriter $outbox,
    ) {}

    /**
     * Every throw happens before the first write, and all four of them sit OUTSIDE the
     * transaction because every one of them is a read. That is the discipline ResetPassword's
     * docblock states and RefreshSession's defect taught: DB::transaction() rolls back on any
     * Throwable, so a throw positioned after a write would undo it.
     *
     * findVisibleTo() is the first statement, which is what makes §5 uniform: a non-member gets
     * PlanNotVisible's one 404 body for a malformed id, an unknown Plan, no membership row, and a
     * membership whose status grants no access - and only a caller who has already proved they
     * can see the Plan can reach any of the refusals below it.
     */
    public function execute(User $actor, string $planId, ?Email $email, MemberRole $role): IssuedInvite
    {
        $context = $this->plans->findVisibleTo($planId, $actor->id);

        // 403, not 404: this member can plainly see the Plan, so there is nothing left to
        // disclose. The owner holds `invite members` too, through §3.1's additive hierarchy
        // (owner ⊇ admin ⊇ member), which PlanPermissions encodes explicitly.
        if (! PlanPermissions::allows($context->role, Capability::InviteMembers)) {
            throw new AuthorizationException;
        }

        // An Admin may mint `member` invites only. `assign admins` is owner-only (§3.2), so an
        // admin who can mint an admin-role invite has promoted someone with an extra step. This
        // is the second of the two places that rule is enforced - ChangeMemberRole is the other -
        // and they would otherwise disagree.
        if (! PlanPermissions::canInviteWithRole($context->role, $role)) {
            throw new AuthorizationException;
        }

        // Compared against PlanStatus::Active->value rather than cast: plans.status is a plain
        // varchar with NO enum cast and NO CHECK constraint, and whether to add a cast was
        // deferred to this wave; a cast turns any unrecognised stored value into a ValueError
        // during hydration, which is a worse failure on a read path than a string comparison.
        if ($context->plan->status !== PlanStatus::Active->value) {
            throw new PlanNotEditable;
        }

        // Visibility's own docblock settles what the column means: "a Plan is shared with the
        // people who were invited to it, and `private` is the narrower 'only me' state" - and for
        // a Plan that already has members the only non-destructive reading of "only me" is "no
        // new people". Written now, although no Cycle 1 endpoint can set the column, because
        // PlanFactory already can: a Plan created private by a future endpoint must not be
        // invitable by an endpoint written before the rule existed.
        if ($context->plan->visibility === Visibility::Private->value) {
            throw new PlanNotInvitable;
        }

        return DB::transaction(function () use ($actor, $context, $email, $role): IssuedInvite {
            // One transaction: mint the token and insert the row, write the activity row, and -
            // for the emailed channel only - write the outbox event. All three commit together,
            // so a Plan that was not invited to has no "invited" row and no email is ever sent
            // for an invite that did not commit. Register::execute() states the same rule.
            // $minted is App\Infrastructure\Auth\MintedInvite - the saved row, the plaintext and
            // the actor's display name. It stops here: what this method RETURNS is
            // App\Application\Plans\IssuedInvite, eight typed scalars, built at the bottom of
            // this closure. Login::execute() maps IssuedAccessToken/IssuedRefreshToken into
            // Session the same way and for the same reason - Http must not be handed a value
            // with an Eloquent model, and this one carries token_hash.
            $minted = $this->tokens->issue($context->plan, $actor, $email, $role);

            $this->activity->execute(
                $context->plan->id,
                $actor->id,
                ActivityType::MemberInvited,
                // RecordActivity::SUBJECT_INVITE, not the literal 'PlanInvite'. The two agree
                // today, but the value belongs to the section that owns RecordActivity, which
                // matches this argument against its own per-type table and throws
                // InvalidArgumentException - an uncaught 500 - on a disagreement. A literal here
                // would keep this class and its own test agreeing with each other while drifting
                // from the validator, surfacing as a 500 on every invite issued. No import: both
                // classes are in App\Application\Plans.
                RecordActivity::SUBJECT_INVITE,
                $minted->invite->id,
                // Channel and role, and NEVER the address. The feed is read by every member, and
                // an invited address is personal data about someone who is not a member
                // (blueprint §126); only owners and admins can see the invite list. The rendered
                // copy is "Denis sent an invite" rather than naming a stranger.
                ['channel' => $email === null ? 'link' : 'email', 'role' => $role->value],
            );

            if ($email !== null) {
                // The plaintext travels in the outbox payload because the dispatcher builds the
                // email after the commit and the sha256 in plan_invites cannot produce a link.
                // It is ENCRYPTED, not plaintext: OutboxDispatcher sets published_at and NEVER
                // deletes the row, so a plaintext token here would sit in outbox_events.payload
                // indefinitely, readable by anyone with database access - which blueprint §142.7
                // forbids. Crypt uses APP_KEY, which is not in the database, so a dump yields
                // nothing usable. This was caught in Wave 1a Task 6 before it shipped, with a
                // false rationale ("the row is deleted once published") replaced by the real one.
                //
                // The ADDRESS is deliberately not in the payload either. The handler reads it off
                // the invite row instead, so that §14.1 clause 2's nulling of an unaccepted
                // invite's email actually removes it - a copy in an outbox row the dispatcher
                // never deletes would outlive the deletion it is supposed to honour.
                //
                // 'PlanInvite' as the aggregate type is the convention OutboxWriter already
                // carries ('User' for the two existing types), and plan.invited is the FOURTH
                // event type ('user.registered', 'password.reset_requested' and 'user.deleted'
                // are already registered); OutboxHandlers::register() throws on a duplicate, so
                // the literal only has to be new and a collision fails loudly at boot.
                $this->outbox->write('PlanInvite', $minted->invite->id, 'plan.invited', [
                    'invite_id' => $minted->invite->id,
                    'token' => Crypt::encryptString($minted->token),
                ]);
            }

            // fromInvite(), inside the transaction, from the row just written: the eight scalars
            // the 201 publishes, and the plaintext handed in rather than read off the model,
            // because the model only ever held its sha256.
            return IssuedInvite::fromInvite($minted->invite, $minted->inviterName, $minted->token);
        });
    }
}
