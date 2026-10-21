<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\InviteOutcome;
use App\Domain\Plans\InvitePolicy;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Infrastructure\Auth\PlanInviteTokens;
use App\Models\Plan;
use App\Models\User;

/**
 * DESIGN NOTE, not in the original dispatch: InviteRepository (shipped by the persistence
 * section, ahead of this task) carries a FIFTH method beyond the four this section's own
 * inherited rules name - findPreviewByToken(string $plainToken): ?InvitePreview, which already
 * returns this exact four-scalar shape from one query, with its own repository-level test.
 *
 * It is deliberately NOT used below. Two measured reasons, not one:
 *
 * 1. It cannot serve the policy decision or the archived-Plan refusal on its own - its query
 *    inner-joins plans with no status filter and selects nothing invite-state-related, so
 *    InvitePolicy::decide() and the "Plan is no longer active" branch below are both
 *    unreachable from it. findByToken() (below) is needed regardless.
 * 2. Its leftJoin to user_profiles carries no users.deleted_at filter, so for an invite whose
 *    inviter has been SOFT-deleted (but not yet purged - invited_by is still set) it returns
 *    that user's display name rather than null. That is a real, reachable intermediate state
 *    between a user's own account deletion and the day-30 purge's nullOnDelete, and this
 *    class's own caller-facing contract is that a departed inviter's name is withheld (S-3) -
 *    which is exactly what "reports a null inviter name when the inviter no longer resolves"
 *    (PreviewInviteTest) exercises, by soft-deleting the inviter rather than purging
 *    invited_by. Routing through findPreviewByToken() here would make that test pass on an
 *    artefact that discloses a departed user's name during that window - a privacy regression
 *    this task will not ship to avoid a second query.
 *
 * So this use case hand-assembles the same four scalars through Eloquent instead, exactly as
 * originally dispatched: User::query()->find() honours SoftDeletes and returns null for a
 * soft-deleted inviter, which is the behaviour the test above actually requires.
 *
 * The honest cost of that choice, disclosed rather than hidden: findPreviewByToken() keeps
 * ZERO production call sites after this task (it already had one focused Feature test before
 * this task touched anything), and the member-count query below is a FOURTH independent
 * "count a Plan's active members" implementation in this codebase - findPreviewByToken()'s own
 * docblock discloses three and says a future eligibility ruling "would need to be applied in
 * all three places or none," which this task's addition makes untrue without editing that
 * file. Fixing either costs editing a file this task does not own (EloquentInviteRepository,
 * shipped by the persistence section) to add a users.deleted_at filter to a query with its own
 * already-green test - judged Extra for this task rather than done silently. Not fixed; not
 * proved either way beyond what is stated here.
 */
final readonly class PreviewInvite
{
    public function __construct(
        private InviteRepository $invites,
        private PlanInviteTokens $tokens,
    ) {}

    /**
     * Unauthenticated: there is no actor to resolve, and the token is the whole credential.
     * Reads only - no transaction, and no lockForUpdate(), for the reason the repository's
     * findByToken() docblock gives.
     *
     * EVERY refusal below throws the same InviteNotUsable, and that is the deliverable rather
     * than a convenience: unknown, malformed, expired, revoked, already accepted, and a Plan
     * that is no longer active must be indistinguishable to the caller.
     *
     * Deliberately does not special-case an owner-role invite (plan_invites.role = 'owner').
     * Whichever use case accepts an invite is the one that must refuse that role - the column
     * carries no CHECK constraint, so a refusal has to live in code rather than in the schema.
     * This preview publishes no `role` field at all (InvitePreview carries four scalars, none
     * of them role), so there is nothing here that could imply an owner-role invite is
     * acceptable. Nothing to decide; flagged so a future reader does not reopen the question
     * from this file.
     *
     * @throws InviteNotUsable
     */
    public function execute(string $plainToken): InvitePreview
    {
        $invite = $this->invites->findByToken($plainToken);

        if ($invite === null) {
            throw new InviteNotUsable;
        }

        if (InvitePolicy::decide($this->tokens->stateOf($invite)) === InviteOutcome::Reject) {
            throw new InviteNotUsable;
        }

        $plan = Plan::query()->find($invite->plan_id);

        // plan_invites.plan_id is NOT NULL with CASCADE, so a row that was just read always has
        // its Plan and this branch is unreachable - the same kind of unreachable-but-required
        // branch as RequiresAuthenticatedUser's RuntimeException. It is here because find() is
        // honestly nullable and narrowing it with an inline @var is forbidden at level 8, and it
        // throws the same exception as every other refusal so that an impossible state cannot
        // leak a different body.
        if ($plan === null) {
            throw new InviteNotUsable;
        }

        // An archived Plan reports InviteNotUsable, NOT PlanNotEditable. This route is reached by
        // people who are not members, and a second, more specific status would make the invite
        // surface the one place in the API that discloses a Plan's state to a non-member.
        //
        // !== PlanStatus::Active->value, not === Archived: the same predicate UpdatePlan's
        // editability check uses, so a status this column ever grows beyond Active/Archived
        // still refuses here rather than defaulting to "usable".
        if ($plan->status !== PlanStatus::Active->value) {
            throw new InviteNotUsable;
        }

        $inviter = $invite->invited_by === null
            ? null
            : User::query()->with('profile')->find($invite->invited_by);

        return new InvitePreview(
            $plan->id,
            $plan->title,
            // No null check on ->profile, for the reason UserResource records:
            // user_profiles.user_id is UNIQUE NOT NULL and registration is the only path that
            // creates a user, so the relation is a database-level invariant once the user is
            // non-null. $inviter itself IS nullable - the column is, and a soft-deleted inviter
            // does not resolve through User's SoftDeletes scope.
            $inviter === null ? null : $inviter->profile->display_name,
            $plan->members()->where('status', MemberStatus::Active->value)->count(),
        );
    }
}
