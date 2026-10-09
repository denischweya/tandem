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
use App\Models\PlanMember;
use App\Models\User;
use DateTimeImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Spec §9.3 steps 1-5, in one transaction with the outbox event. Cycle 1's procedure is
 * narrower than full §127 because there are no calendar connections, provider tokens or
 * linked OAuth identities yet; the calendar cycle adds the revocation steps where those
 * connections first exist.
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
     * Note that the rollback is wanted here, which is the opposite of Task 5's situation: a
     * throw inside that transaction wrongly undid a chain revocation that had to persist.
     * The difference is that nothing in this method is a security action that must survive
     * the failure of a later one - revoking the sessions is only correct if the account
     * really is being deleted.
     */
    public function execute(User $user): void
    {
        DB::transaction(function () use ($user): void {
            $now = $this->clock->now();

            // Step 4 first: anonymise before anything else touches the feed, so an ownership
            // transfer that writes activity later cannot be anonymised by accident.
            PlanActivity::query()->where('actor_id', $user->id)->update(['actor_id' => null]);

            // Step 3. Before Plans, because groups.owner_id is NOT NULL with RESTRICT and a
            // Group deletion sets plans.group_id to NULL - which must happen before the Plan
            // branch reads those Plans.
            $this->resolveGroupOwnership($user);

            // Step 2.
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

            // Step 1. Every refresh token and every Sanctum access token the account holds.
            // revokeAllFor() rather than a third copy of $user->tokens()->delete(): it is
            // keyed on the id, not on a loaded model, so it cannot be silently skipped by
            // User's SoftDeletes scope - and Task 6 reused it for the same reason.
            $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => $now]);
            $this->accessTokens->revokeAllFor($user->id);
            $user->devices()->delete();

            // Step 5. A soft delete: users.deleted_at exists so the day-30 purge (Task 9) has
            // something to find, and so plans.owner_id's RESTRICT is never asked to fire here.
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
            ->where('status', MemberStatus::Active->value);

        if ($role !== null) {
            $candidates->where('role', $role->value);
        }

        return $candidates->orderBy('joined_at')->orderBy('id')->first();
    }
}
