<?php

declare(strict_types=1);

namespace App\Application\Users;

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Domain\Shared\Clock;
use App\Infrastructure\Auth\AccessTokenRevoker;
use App\Infrastructure\Outbox\OutboxWriter;
use App\Models\Group;
use App\Models\GroupMember;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\PlanInvite;
use App\Models\PlanMember;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/**
 * Spec §9.3 steps 1-5, in one transaction with the outbox event. Cycle 1's procedure is
 * narrower than full §127 because there are no calendar connections, provider tokens or
 * linked OAuth identities yet; the calendar cycle adds the revocation steps where those
 * connections first exist.
 *
 * Plus the two clauses the Wave 2 design document's §14.1 adds to spec §9.3 (recorded in its
 * §19 amendment table): the departing user's outstanding invites are revoked, and the `email`
 * of unaccepted invites addressed to them is nulled. Wave 1a Task 8 resolved `plan_invites`
 * as out of scope here and answered only half of it - the schema handles the historical trail
 * at hard-delete time, through `invited_by`/`accepted_by`'s `nullOnDelete`, which says nothing
 * about whether a LIVE credential issued by a deleted account keeps working. It does not, as
 * of §14.1: an invite is an exercise of the inviter's authority over a Plan, and the 14-day
 * TTL is a product convenience rather than a security bound.
 */
final readonly class DeleteAccount
{
    public function __construct(
        private Clock $clock,
        private AccessTokenRevoker $accessTokens,
        private OutboxWriter $outbox,
    ) {}

    /**
     * Everything below is inside one transaction, including the outbox write, and that is
     * the load-bearing part rather than a tidy-up: a failure after step 1 but before step 5
     * would leave an account whose sessions are all revoked and whose row is still live -
     * someone locked out of an account they did not finish deleting, which is the worst
     * outcome available here.
     *
     * Note that the rollback is wanted here, which is the opposite of
     * RefreshSession::execute(), where a throw inside the transaction wrongly undid a chain
     * revocation that had to persist (see that method's docblock). The difference is that
     * nothing here is a security action that must survive the failure of a later one -
     * revoking the sessions is only correct if the account really is being deleted.
     */
    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $now = $this->clock->now();

            // Step 4. Nothing in this method writes plan_activity, so no ordering against
            // the two ownership branches is at stake today - it is first only so that it
            // stays first if a later cycle makes an ownership transfer write an activity row,
            // which must not then be anonymised by this same call.
            PlanActivity::query()->where('actor_id', $user->id)->update(['actor_id' => null]);

            // Steps 3 and 2, in that order, and the order between THESE TWO IS IMMATERIAL -
            // recorded because an earlier version of this comment claimed otherwise and would
            // have had someone preserve a dependency that does not exist. Deleting a Group
            // does set plans.group_id to NULL (SET NULL in the migration), but
            // resolvePlanOwnership() loads its Plans with a fresh query that runs after this
            // line, so those rows already carry whatever the SET NULL left - and it never
            // reads group_id in any case, writing only owner_id, status and archived_at
            // through forceFill()->save(), which sends dirty attributes only.
            //
            // What IS load-bearing is that both run before step 5: plans.owner_id and
            // groups.owner_id are NOT NULL with RESTRICT, and step 5's soft delete is what
            // keeps that RESTRICT from ever being asked here - the day-30 hard delete is
            // where it finally is.
            $this->resolveGroupOwnership($user);
            $this->resolvePlanOwnership($user, $now);

            // The role is demoted along with the status, in the one update that was already
            // running. Without it a transferred Plan carries two rows claiming
            // role = 'owner' - the departing one, now `left`, and the successor promoted
            // just above - which is schema-legal, since the only unique key here is
            // (plan_id, user_id), and wrong for any reader that answers "who owns this Plan"
            // from plan_members.role rather than from plans.owner_id.
            PlanMember::query()
                ->where('user_id', $user->id)
                ->update([
                    'status' => MemberStatus::Left->value,
                    'role' => MemberRole::Member->value,
                ]);

            // §14.1 clause 1. Every invite this user sent that nobody has accepted and nobody
            // has revoked. Without it, a live credential issued by an account that no longer
            // exists stays acceptable for the rest of PlanInviteTokens::TTL_DAYS (14) - and the
            // Plan it opens may be one resolvePlanOwnership() handed to an admin six lines
            // above, who never approved that invite. The one person who could have revoked it no
            // longer exists.
            //
            // NOT plan-scoped, deliberately: an invite this user sent as an admin on somebody
            // else's Plan is still this user's exercise of authority.
            //
            // `revoked_at IS NULL` as well as `accepted_at IS NULL`: without it, an invite
            // revoked three weeks ago is re-stamped with this instant, which rewrites the trail
            // that this table's nullOnDelete choices exist to protect. An accepted invite is
            // exempt because somebody joined through it - there is no live credential left to
            // end, and the row is the membership history blueprint §127 step 6 preserves.
            //
            // No plan_activity row for either clause. Step 4 above has already nulled actor_id
            // for this user, so a row written here with actor_id = $user->id would escape the
            // anonymisation this same transaction just performed, and a row written before step
            // 4 would be anonymised into "someone revoked an invite". The user.deleted outbox
            // event below is the record of the deletion.
            PlanInvite::query()
                ->where('invited_by', $user->id)
                ->whereNull('accepted_at')
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $now]);

            // §14.1 clause 2. Blueprint §127 step 3 requires removing personal information, and
            // an unaccepted invite row carrying a deleted user's address is exactly that, with no
            // trail value because nobody joined through it. An ACCEPTED invite keeps its address:
            // accepted_by makes it part of the Plan's membership history, which blueprint §127
            // step 6 preserves, and which is what both resource layers argue
            // PlanInviteView::$invitedByName's nullability from.
            //
            // Legal rather than a 23514: plan_invites' lowercase constraint is NULL-tolerant by
            // construction - CHECK (email IS NULL OR email = lower(email)), measured in
            // 2026_10_06_000200_create_plan_tables.php - so NULL is a value it admits.
            //
            // The equality needs no lower() on either side, and adding one would be worse than
            // noise: users.email carries CHECK (email = lower(email)) and plan_invites.email
            // carries the NULL-tolerant form of the same rule, so both are lowercase at the
            // database level, and a case-insensitive comparison here would hide a constraint
            // violation that cannot occur.
            //
            // No `revoked_at IS NULL` here, unlike clause 1: an invite that was revoked and never
            // accepted still carries the address, and revocation is not erasure. Read $user->email
            // before step 5 purely for readability - the soft delete below leaves the attribute
            // in memory, so this is not a dependency.
            PlanInvite::query()
                ->where('email', $user->email)
                ->whereNull('accepted_at')
                ->update(['email' => null]);

            // Step 1. Every refresh token and every Sanctum access token the account holds.
            // revokeAllFor() rather than a third copy of $user->tokens()->delete(), simply so
            // that "delete every access token this user holds" has one implementation - the
            // same one RefreshSession's replay branch and ResetPassword call. The soft-delete
            // reasoning in AccessTokenRevoker's docblock is about ITS other callers, not this
            // one: step 5 has not run yet here, so $user is still a live row.
            $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => $now]);
            $this->accessTokens->revokeAllFor($user->id);
            $user->devices()->delete();

            // Step 5. A soft delete: users.deleted_at exists so the day-30 purge
            // (App\Console\Commands\PurgeDeletedUsersCommand) has something to find, and so
            // plans.owner_id's RESTRICT is never asked to fire here.
            $user->delete();

            $this->outbox->write('User', $user->id, 'user.deleted', [
                'user_id' => $user->id,
                'soft_deleted_at' => $now->format(DATE_RFC3339_EXTENDED),
            ]);
        });
    }

    /**
     * No $now parameter, deliberately: this branch writes no timestamp of its own. The only
     * date it touches is group_members.created_at, which it reads to order candidates, and
     * the updated_at on a transferred Group, which Eloquent fills in the same way it does for
     * every other model in this application. A $now threaded through here and never used
     * would be the kind of dead weight that makes a reader doubt the rest of the method.
     */
    private function resolveGroupOwnership(User $user): void
    {
        foreach (Group::query()->where('owner_id', $user->id)->get() as $group) {
            $successor = GroupMember::query()
                ->where('group_id', $group->id)
                ->where('user_id', '!=', $user->id)
                // The candidate's ACCOUNT has to be usable, not just their membership row -
                // see successorFor() below for the full account of why, which applies here
                // with one extra consequence: groups.owner_id has no archive branch to fall
                // back on, so a Group left pointing at a soft-deleted user makes that user's
                // day-30 forceDelete() hit RESTRICT every night for ever, with the purge
                // recording a failure nothing can resolve.
                ->whereHas('user', fn (Builder $candidate) => $candidate->whereNull('suspended_at'))
                // created_at is "longest-standing" for a Group: group_members has no
                // joined_at, because joining a Group is not a two-step invitation.
                ->orderBy('created_at')
                ->orderBy('id')
                ->first();

            if ($successor === null) {
                // Safe precisely because plans.group_id is nullable with SET NULL: every Plan
                // that came from this Group survives with its association cleared.
                $group->delete();

                continue;
            }

            $group->forceFill(['owner_id' => $successor->user_id])->save();
        }

        GroupMember::query()->where('user_id', $user->id)->delete();
    }

    private function resolvePlanOwnership(User $user, DateTimeImmutable $now): void
    {
        foreach (Plan::query()->where('owner_id', $user->id)->get() as $plan) {
            // An admin first, and only then anyone else. Without the first attempt a plain
            // member who happens to have joined earlier beats every admin, which hands the
            // Plan to someone the departing owner never promoted.
            $successor = $this->successorFor($plan, $user, MemberRole::Admin)
                ?? $this->successorFor($plan, $user, null);

            if ($successor === null) {
                $plan->forceFill([
                    'status' => PlanStatus::Archived->value,
                    'archived_at' => $now,
                ])->save();

                continue;
            }

            $plan->forceFill(['owner_id' => $successor->user_id])->save();
            $successor->forceFill(['role' => MemberRole::Owner->value])->save();
        }
    }

    /**
     * Longest-standing active member, optionally restricted to a role.
     *
     * An `if`, not ->when($role !== null, fn () => ...): a closure captures $role as the
     * nullable it is declared to be, so $role->value inside one is an access on a possible
     * null that PHPStan is right to reject and that only a cast or an inline @var would
     * silence. The narrowing is free this way.
     *
     * joined_at ascending, then id: Postgres sorts NULLs last on ASC, so an active row with
     * no joined_at loses to every row that has one rather than winning, and id breaks the
     * tie two members who joined in the same microsecond would otherwise leave to chance.
     */
    private function successorFor(Plan $plan, User $departing, ?MemberRole $role): ?PlanMember
    {
        $candidates = PlanMember::query()
            ->where('plan_id', $plan->id)
            ->where('user_id', '!=', $departing->id)
            // Active only: handing a Plan to someone who was invited and never accepted gives
            // it an owner who cannot see it.
            ->where('status', MemberStatus::Active->value)
            // ...and the row saying `active` is not the same thing as the ACCOUNT being
            // usable. This method filtered only plan_members.status, which left two ways to
            // hand a Plan to someone who can never administer it:
            //
            // A SOFT-DELETED member. This method marks the DEPARTING user's rows `left`, not
            // anyone else's, so a member who already deleted their own account still has an
            // `active` row here. Two members of one Plan both calling DELETE /api/v1/me is
            // not an exotic race - it is the ordinary case of two people leaving - and it
            // committed a live, `active`, multi-member Plan whose owner_id names a
            // soft-deleted row. That Plan then reaches the day-30 purge, where it is either
            // destroyed (what PurgeDeletedUsersCommand used to do) or a permanent RESTRICT
            // failure. whereHas('user', ...) is what closes it: the relation respects User's
            // SoftDeletes global scope, so a deleted account has no matching row at all.
            //
            // A SUSPENDED member. Login::execute() refuses a suspended account outright, so
            // the new owner cannot sign in to administer the Plan they were just handed -
            // exactly the defect the `active` filter above exists to prevent, by a different
            // route. suspended_at is a live concept, not a future one.
            //
            // whereHas rather than a join, because the SoftDeletes scope is what does half
            // the work here and a manual join would have to restate `deleted_at IS NULL`
            // alongside this `suspended_at IS NULL` - two copies of one rule, free to
            // disagree, in the method where disagreeing is most expensive.
            ->whereHas('user', fn (Builder $candidate) => $candidate->whereNull('suspended_at'));

        if ($role !== null) {
            $candidates->where('role', $role->value);
        }

        return $candidates->orderBy('joined_at')->orderBy('id')->first();
    }
}
