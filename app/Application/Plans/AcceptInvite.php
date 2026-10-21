<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\InviteOutcome;
use App\Domain\Plans\InvitePolicy;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Domain\Shared\Clock;
use App\Infrastructure\Auth\PlanInviteTokens;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

final readonly class AcceptInvite
{
    public function __construct(
        private InviteRepository $invites,
        private PlanMemberRepository $members,
        private PlanRepository $plans,
        private PlanInviteTokens $tokens,
        private RecordActivity $activity,
        private Clock $clock,
    ) {}

    /**
     * The one Plan route a non-member must be able to reach, so §8.1's blanket 404 cannot apply
     * in the usual way: the invite token is the credential, not membership. What takes its place
     * is that EVERY failure answers the same InviteNotUsable 404 - a wrong {plan}, a Plan that
     * does not exist, a token for a different Plan, an expired token, a revoked token, a token
     * already used, and an archived Plan are all indistinguishable - with one deliberate
     * exception: an owner-role invite answers 409, loudly, because that row is a data-integrity
     * defect rather than ordinary expired-link traffic (see OwnerInviteNotAcceptable).
     *
     * findVisibleTo() is therefore NOT the first statement, which is the opposite of every other
     * use case in this section. It is called once, AFTER the commit, to build the response -
     * CreatePlan::execute()'s shape, and it buys a loud failure: if the membership write were
     * ever dropped, this endpoint would answer 404 rather than hand a Plan to someone who is not
     * a member of it.
     *
     * THE CATCH SITS AROUND DB::transaction(), NEVER INSIDE THE CLOSURE, and that is measured
     * rather than preferred. A find-then-insert is a TOCTOU gap: two concurrent joins by one user
     * can both pass the membership read, and the second insert violates
     * UNIQUE(plan_id, user_id). Catching inside the closure "succeeds" - a real exception with
     * correct columns - and then EVERY subsequent statement fails with 25P02 ("current
     * transaction is aborted, commands ignored until end of transaction block"), so a
     * fall-through to step 5's already-a-member answer would read as correct, log a
     * correct-looking caught exception, and then 500 on the next read. Around the transaction,
     * the rollback has already happened, $e is the real exception, and the connection is
     * reusable.
     *
     * @throws InviteNotUsable
     * @throws OwnerInviteNotAcceptable
     * @throws PlanNotVisible
     * @throws UniqueConstraintViolationException when a collision is NOT the race below - the
     *                                            row is absent or not active, so nothing
     *                                            committed and a 500 is the honest answer
     */
    public function execute(User $actor, string $planId, string $plainToken): PlanContext
    {
        try {
            DB::transaction(function () use ($actor, $planId, $plainToken): void {
                $this->join($actor, $planId, $plainToken);
            });
        } catch (UniqueConstraintViolationException $e) {
            // Scoped on $e->columns and NEVER on $e->index: PostgresConnection::
            // parseUniqueConstraintViolation() fills `columns` by regex-parsing the driver's
            // DETAIL text ("Key (plan_id, user_id)=(…) already exists."), so the scoping does not
            // depend on Laravel's index-naming convention - while $e->index would. A violation on
            // any other constraint is somebody else's bug and is rethrown untouched.
            if ($e->columns !== ['plan_id', 'user_id']) {
                throw $e;
            }

            // The retry is this read, not a second run of join(). Re-running the whole join would
            // re-enter the policy check first, where the state is now Accepted - the request that
            // won claimed the invite - and would answer 404 to a user who IS a member, which is
            // the opposite of what step 5 exists for.
            $membership = $this->members->findMembership($planId, $actor->id);

            // Absent, or present and not active: then nothing committed and the collision was not
            // the race this catch is for. Rethrowing is the honest answer - a 500 for a state
            // nobody has explained - rather than a 200 that invents a membership.
            if ($membership === null || $membership->status !== MemberStatus::Active->value) {
                throw $e;
            }
        }

        // One read, both paths. The actor is an active member by the time control reaches here, so
        // this cannot throw PlanNotVisible unless the membership write above was removed.
        return $this->plans->findVisibleTo($planId, $actor->id);
    }

    /**
     * §10.7's eight steps, in §10.7's order, with EVERY THROW BEFORE THE FIRST WRITE. That
     * discipline is ResetPassword's stated rule and RefreshSession's defect taught it:
     * DB::transaction() rolls back on any Throwable, so a throw positioned after a write would
     * undo it. The first write in this method is the claim, and nothing below it can throw
     * except the unique violation the caller catches.
     */
    private function join(User $actor, string $planId, string $plainToken): void
    {
        // 1. The lock, inside the transaction, exactly as ResetPassword takes it.
        $invite = $this->invites->findByTokenForUpdate($plainToken);

        if ($invite === null) {
            throw new InviteNotUsable;
        }

        // 2. A string comparison against a column just read - which is also what keeps a
        //    malformed {plan} away from a uuid column, where it would be Postgres 22P02 and
        //    therefore a 500. A mismatch must be indistinguishable from an unknown token, so it
        //    throws the same exception as every other refusal here.
        if ($invite->plan_id !== $planId) {
            throw new InviteNotUsable;
        }

        // 3. plan_invites.plan_id is NOT NULL with CASCADE, so a row that was just read always
        //    has its Plan and the null branch is unreachable - it exists because find() is
        //    honestly nullable and narrowing it with an inline @var is forbidden at this
        //    project's PHPStan level.
        $plan = Plan::query()->find($invite->plan_id);

        if ($plan === null) {
            throw new InviteNotUsable;
        }

        // An archived Plan is InviteNotUsable (404), NOT PlanNotEditable (409): this route is
        // reached by people who are not members, and a second, more specific status would make
        // the join surface disclose a Plan's state to a non-member. Compared against
        // PlanStatus::Active->value rather than cast: plans.status is a plain varchar with no
        // CHECK, and a cast turns any unrecognised stored value into a ValueError during
        // hydration.
        if ($plan->status !== PlanStatus::Active->value) {
            throw new InviteNotUsable;
        }

        // 4. Expiry, revocation and reuse, resolved into InviteState by Infrastructure and
        //    decided by the pure policy.
        if (InvitePolicy::decide($this->tokens->stateOf($invite)) === InviteOutcome::Reject) {
            throw new InviteNotUsable;
        }

        // Human ruling (controller, 2026-10-20): an owner-role invite is refused outright, before
        // the membership read and before any write, regardless of whether the actor already holds
        // an active row. The migration freeze forbids a CHECK on plan_invites.role, so this is the
        // only place §9.1's one-owner invariant can be protected on this path. A plain string
        // comparison against the literal value, never from() or tryFrom(): MemberRole::Owner is a
        // legal case, so from('owner') would succeed and buy nothing against the hazard - the
        // defect is the second row this would write, not an unrecognised value.
        //
        // Nothing in this API can mint such an invite (InvitePlanMemberRequest's
        // in:admin,member, enforced by PlanPermissions::canInviteWithRole()), so reaching this
        // branch means the row was written directly against the database - which is exactly why
        // it is logged rather than treated as the same expected traffic as an expired or revoked
        // link.
        if ($invite->role === MemberRole::Owner->value) {
            Log::warning('security.invite.owner_role_refused', [
                'event' => 'security.invite.owner_role_refused',
                'plan_id' => $planId,
                'invite_id' => $invite->id,
                'actor_id' => $actor->id,
            ]);

            throw new OwnerInviteNotAcceptable;
        }

        // Any status, including left and removed: §3.5 retains those rows for ever and
        // UNIQUE(plan_id, user_id) means they are the rows step 7 has to reuse.
        $membership = $this->members->findMembership($planId, $actor->id);

        // 5. Already an active member: answer 200 with the Plan and write NOTHING. A
        //    double-tapped accept button must not fail and must not burn a single-use credential
        //    for nothing; leaving the invite pending costs nothing, because it still expires and
        //    can still be revoked. This arm also never changes an existing active member's role -
        //    accepting an admin invite while already a Member would be a promotion through the
        //    join route, bypassing `assign admins` (§3.2).
        if ($membership !== null && $membership->status === MemberStatus::Active->value) {
            return;
        }

        // 6. The first write.
        $this->tokens->claim($invite, $actor);

        // 7. The upsert. Found-and-assigned rather than updateOrCreate($attrs, $values), which
        //    mass-assigns and therefore throws MassAssignmentException against $guarded = ['*'].
        //    Every field is assigned explicitly: plan_members.role defaults to 'member' and
        //    status to 'invited' in the schema, so an omitted field would silently take a column
        //    default instead of the invite's value.
        //
        //    $invite->role is copied across as the stored string rather than passed through
        //    MemberRole::from(): the value was written by issue() from a MemberRole, and
        //    InvitePlanMemberRequest's in:admin,member plus canInviteWithRole() mean nothing in
        //    the API can put anything but admin or member there once the owner case above has
        //    already been refused. Copying one varchar into another cannot fail, while from()
        //    would raise ValueError on any unrecognised stored value.
        $member = $membership ?? new PlanMember;
        $member->plan_id = $plan->id;
        $member->user_id = $actor->id;
        $member->role = $invite->role;
        $member->status = MemberStatus::Active->value;
        // From Clock, never now(): blueprint §171.4, and non-null because a keyset page over
        // (joined_at, id) drops a NULL-joined_at active row from every page after the first.
        // Carbon::instance(), not a bare assignment: Clock::now() returns DateTimeImmutable, and
        // PlanMember's joined_at is cast 'datetime', which larastan types as
        // Illuminate\Support\Carbon|null on the property - CreatePlan's own member write takes
        // the identical wrap for the identical reason.
        $member->joined_at = Carbon::instance($this->clock->now());
        $member->save();

        // 8. Inside the transaction, after the write it describes, so a join that did not commit
        //    has no "joined" row. MemberJoined carries the member's own user_id as the subject and
        //    no metadata at all - the activity section's table is the authority on that shape, and
        //    a disagreement is an InvalidArgumentException and a 500.
        $this->activity->execute(
            $plan->id,
            $actor->id,
            ActivityType::MemberJoined,
            RecordActivity::SUBJECT_USER,
            $actor->id,
            [],
        );
    }
}
