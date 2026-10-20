<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Plans\CursorPage;
use App\Application\Plans\PageQuery;
use App\Application\Plans\PlanMemberNotFound;
use App\Application\Plans\PlanMemberRepository;
use App\Application\Plans\PlanMemberView;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Shared\PageCursor;
use App\Models\PlanMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The second repository implementation, and it follows EloquentPlanRepository's shape
 * deliberately: reads only, the uuid shape check in front of every query, and
 * limit($size + 1) as the whole of "is there a next page".
 */
final readonly class EloquentPlanMemberRepository implements PlanMemberRepository
{
    public function findActiveMember(string $planId, string $userId): PlanMember
    {
        $member = $this->activeMemberQuery($planId, $userId)?->first();

        if ($member === null) {
            throw new PlanMemberNotFound;
        }

        return $member;
    }

    public function findTransferTarget(string $planId, string $userId): PlanMember
    {
        $query = $this->activeMemberQuery($planId, $userId);

        if ($query === null) {
            throw new PlanMemberNotFound;
        }

        // DeleteAccount::successorFor()'s filter, and the reasoning is already measured and
        // written down in that method's comment:
        //
        //   a SOFT-DELETED member still has an `active` membership row, so without the account
        //   check a Plan can be handed to a row whose user is gone - and that Plan "then
        //   reaches the day-30 purge, where it is either destroyed or a permanent RESTRICT
        //   failure";
        //
        //   a SUSPENDED member cannot sign in at all (Login::execute() refuses outright), "so
        //   the new owner cannot sign in to administer the Plan they were just handed".
        //
        // whereHas rather than a join, for that method's reason too: the relation respects
        // User's SoftDeletes global scope, so a deleted account has no matching row at all,
        // and a manual join would have to restate `deleted_at IS NULL` alongside this
        // `suspended_at IS NULL` - two copies of one rule, free to disagree.
        //
        // Which also means the two halves need separate mutations to prove, and not the one a
        // first reading suggests: deleting this line entirely stops refusing a suspended target
        // AND a soft-deleted one, because with no `users` subquery there is neither a
        // suspension check nor a SoftDeletes scope left. Measured, against a prediction to the
        // contrary. ->whereHas('user') with no closure is what isolates the suspension half,
        // and withTrashed() inside this closure the soft-delete half.
        $target = $query
            ->whereHas('user', fn (Builder $account) => $account->whereNull('suspended_at'))
            ->first();

        if ($target === null) {
            throw new PlanMemberNotFound;
        }

        return $target;
    }

    public function findActiveMemberView(string $planId, string $userId): PlanMemberView
    {
        // The shape check is repeated rather than reached through activeMemberQuery(): that
        // helper's predicates are unqualified (`where('user_id', ...)`, `where('status', ...)`),
        // and user_profiles carries user_id, id, created_at and updated_at of its own - so
        // joining it onto that builder is a Postgres 42702 "column reference is ambiguous",
        // not a narrower query. Every column below is table-qualified for that reason.
        if (! Str::isUuid($planId) || ! Str::isUuid($userId)) {
            throw new PlanMemberNotFound;
        }

        $row = PlanMember::query()
            ->select('plan_members.*')
            ->addSelect('user_profiles.display_name as display_name')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'plan_members.user_id')
            ->where('plan_members.plan_id', $planId)
            ->where('plan_members.user_id', $userId)
            ->where('plan_members.status', MemberStatus::Active->value)
            // No whereNotNull('plan_members.joined_at') here, unlike listActiveMembers(), and
            // the difference is deliberate: there the filter keeps a keyset page consistent,
            // while here it would answer "that person is not a member of this Plan" for someone
            // who demonstrably is. The callers check the S-37 invariant themselves, before their
            // first write, so a corrupt row is reported loudly rather than either silently
            // 404ing or being published with a fabricated joined_at.
            ->first();

        if ($row === null) {
            throw new PlanMemberNotFound;
        }

        $displayName = $row->getAttribute('display_name');

        // The one narrowing this method does itself, for the reason listActiveMembers() states
        // at length: user_profiles.user_id is UNIQUE NOT NULL on the PROFILE side, which gives
        // at most one profile per user and never at least one, so a persisted user with no
        // profile row is exactly what User::factory()->create() produces - and the left join
        // then yields null here. Throwing names the member whose profile is missing; a
        // `(string)` coercion in its place would publish an empty display_name for a real one.
        if (! is_string($displayName)) {
            throw new RuntimeException(
                "plan_members row {$row->id} has no user_profiles row for user {$row->user_id}, "
                .'which registration makes impossible.'
            );
        }

        // PlanMemberView::fromMember() rather than a second hand-built constructor call: it
        // already owns the joined_at refusal (the S-37 invariant, held open by both the analyser
        // and its own named test) and the role/status conversions, its docblock names a
        // single-member read as its intended caller, and listActiveMembers()'s MemberRole::from()
        // ruling - loud over silently dropping a member - reaches this method through it rather
        // than being restated differently one method away.
        return PlanMemberView::fromMember($row, $displayName);
    }

    public function findMembership(string $planId, string $userId): ?PlanMember
    {
        if (! Str::isUuid($planId) || ! Str::isUuid($userId)) {
            return null;
        }

        return PlanMember::query()
            ->where('plan_id', $planId)
            ->where('user_id', $userId)
            ->first();
    }

    public function listActiveMembers(string $planId, PageQuery $query): CursorPage
    {
        if (! Str::isUuid($planId)) {
            return new CursorPage([], null);
        }

        // Named $eloquentQuery, not $query: the parameter is now a PageQuery.
        $eloquentQuery = PlanMember::query()
            ->select('plan_members.*')
            // One left join for the whole page - §8.1's "one eager load, not an N+1". A left
            // join rather than an inner one so that a member with no user_profiles row is
            // reported loudly below instead of disappearing from the list.
            ->addSelect('user_profiles.display_name as display_name')
            ->leftJoin('user_profiles', 'user_profiles.user_id', '=', 'plan_members.user_id')
            ->where('plan_members.plan_id', $planId)
            // §8.1: active rows only. left and removed rows persist for ever (§3.5) and a list
            // showing them would present departed people as current, make blueprint §104's "4
            // members" ambiguous, and tell every member that a specific person was removed.
            //
            // plan_members.status is the only thing filtered here: unlike findTransferTarget()
            // above, this list does not ask whether the member's ACCOUNT is usable. Nothing in
            // app/ writes users.suspended_at today - it is only ever read - and DeleteAccount
            // marks a departing user's memberships `left`, so no row this query returns today
            // belongs to an account that cannot sign in. Whether a suspended member should
            // appear in a member list is a product question rather than this method's.
            ->where('plan_members.status', MemberStatus::Active->value)
            // S-37, and it is correctness rather than hygiene: plan_members.joined_at is
            // NULLable, in no index and under no CHECK, and it is this cursor's leading key. A
            // keyset predicate of the form (joined_at, id) > (?, ?) evaluates to NULL for a
            // NULL joined_at, so such a row would be returned on page 1 and then silently
            // dropped from every page after it. The application's own writers always set it
            // (§7.2 step 2, §10.7 step 7) and PlanMemberFactory::active() sets it too, so this
            // excludes no row either produces - and it is what makes PlanMemberView's
            // non-nullable joinedAt true.
            ->whereNotNull('plan_members.joined_at')
            // Every column qualified: user_profiles carries id, created_at and updated_at too,
            // so an unqualified `id` in the order or the keyset would be ambiguous.
            ->orderBy('plan_members.joined_at')
            ->orderBy('plan_members.id');

        // $query->cursor is already a validated PageCursor or null - see
        // PlanRepository::listForActor() for why nothing here decodes a raw string any more.
        $cursor = $query->cursor;

        if ($cursor !== null) {
            $eloquentQuery->where(function (Builder $keyset) use ($cursor): void {
                $keyset->where('plan_members.joined_at', '>', $cursor->timestampForQuery())
                    ->orWhere(function (Builder $tie) use ($cursor): void {
                        $tie->where('plan_members.joined_at', $cursor->timestampForQuery())
                            ->where('plan_members.id', '>', $cursor->id());
                    });
            });
        }

        $size = self::pageSize($query->limit);
        $rows = $eloquentQuery->limit($size + 1)->get();
        $page = $rows->take($size);

        $items = [];

        foreach ($page as $row) {
            $displayName = $row->getAttribute('display_name');
            $joinedAt = $row->joined_at;

            // Two throws rather than two coercions, for one reason; held open by two DIFFERENT
            // forces, and they are not interchangeable. An earlier version of this comment said
            // both narrowed a type for PHPStan level 8, which is measurably false of one of
            // them - and a reader who believes a guard is held open by something that is not
            // holding it will delete it on the next tidy-up.
            //
            // The is_string throw is held open by a TEST and by nothing else: getAttribute()
            // returns mixed, and mixed-into-string is a level-NINE check, so deleting this
            // throw leaves the analyser at errors: 0 and reds 'refuses to publish a member
            // whose profile row is missing'.
            //
            // And what it buys is the DIAGNOSIS rather than the refusal, which is worth knowing
            // before anyone trims it: with the throw deleted the row is still not published,
            // because PlanMemberView::$displayName is a typed string under strict_types and
            // null reaches it - the measured failure is "Argument #2 ($displayName) must be of
            // type string, null given", a TypeError naming a constructor parameter. This throw
            // is what turns that into a message naming the plan_members row and the user whose
            // profile is missing. A `(string)` coercion in its place is the version that would
            // publish an empty display_name for a real member, and that is the option being
            // refused here.
            //
            // The joined_at throw below is the other way round: it is held open by the ANALYSER,
            // because PlanMemberView::$joinedAt is a non-nullable Carbon while $row->joined_at
            // is Carbon|null. Deleting it is one argument.type error at level 8 with every test
            // in the file still green. Both measured, both ways.
            //
            // The shared reason for throwing is not a type reason at all: registration is the
            // only path that creates a user and it writes the user_profiles row in the same
            // breath, and the query above excludes a NULL joined_at - so neither case is
            // reachable through the application, and coercing instead would publish an empty
            // display_name for a real member.
            //
            // The constraints are not what makes the first one unreachable, and a reader who
            // believes they are will skip this check somewhere it is load-bearing:
            // user_profiles.user_id is UNIQUE NOT NULL on the PROFILE side, which gives at most
            // one profile per user and never at least one. A persisted user with no profile row
            // is exactly what User::factory()->create() produces.
            if (! is_string($displayName)) {
                throw new RuntimeException(
                    "plan_members row {$row->id} has no user_profiles row for user {$row->user_id}, "
                    .'which registration makes impossible.'
                );
            }

            if ($joinedAt === null) {
                throw new RuntimeException(
                    "plan_members row {$row->id} has no joined_at, which this query excludes."
                );
            }

            // The row is filtered to status = 'active' above, so MemberStatus::Active is
            // asserted rather than read back off the row.
            //
            // MemberRole::from(), not tryFrom(), and this is the one place this class is wider
            // than EloquentPlanRepository: listForActor() carries
            // whereIn('plan_members.role', ...) so a row whose role no case recognises is
            // excluded from the result set, while this query has no such filter and an
            // unrecognised plan_members.role here is a ValueError instead.
            //
            // A RULING, received 2026-10-19, not an oversight or an unresolved conflict:
            // from() stays. A member list that silently dropped a real member would
            // under-report membership to every member and contradict §6.1's row-counting
            // member_count, which a Plan list dropping one entry does not - loud beats wrong,
            // even though the ruling knowingly accepts that one corrupt row then makes this
            // Plan's whole member list permanently un-fetchable, with no page a client can
            // route around it. Pinned by "raises a ValueError when a member row's role is
            // corrupt, pinning the ruling's deliberate 500" in this repository's test, against
            // the alternative the ruling rejected: that test reds if this method grows its own
            // whereIn('plan_members.role', ...) filter, the behaviour above borrows the name
            // of but does not carry.
            //
            // The "500" is forward-looking, not a claim about today: there is no route onto
            // this method yet (GET /plans/{plan}/members arrives in Task 21), so right now
            // this ValueError only surfaces out of a repository call with nothing downstream
            // to turn it into an HTTP response. Task 21 is what lets an uncaught ValueError
            // reach bootstrap/app.php's default handler and become {"message":"Server Error"}.
            // plan_members.role is under no CHECK, but every writer in this codebase writes
            // MemberRole::X->value, so the exposure is corrupt data only.
            $items[] = new PlanMemberView(
                $row->user_id,
                $displayName,
                MemberRole::from($row->role),
                MemberStatus::Active,
                $joinedAt,
            );
        }

        $last = $page->last();
        $lastJoinedAt = $last?->joined_at;

        $nextCursor = $rows->count() > $size && $last !== null && $lastJoinedAt !== null
            ? PageCursor::fromKeys($lastJoinedAt->toImmutable(), $last->id)->toString()
            : null;

        return new CursorPage($items, $nextCursor);
    }

    /**
     * Null when either identifier is not uuid-shaped, which every caller turns into its own
     * refusal. §5.1: without the check, `where user_id = 'not-a-uuid'` against a uuid column is
     * Postgres 22P02 and surfaces as a 500 rather than as an answer - and {user} is a path
     * parameter, so it is attacker-chosen.
     *
     * @return Builder<PlanMember>|null
     */
    private function activeMemberQuery(string $planId, string $userId): ?Builder
    {
        if (! Str::isUuid($planId) || ! Str::isUuid($userId)) {
            return null;
        }

        return PlanMember::query()
            ->where('plan_id', $planId)
            ->where('user_id', $userId)
            ->where('status', MemberStatus::Active->value);
    }

    /** §6.2 caps a page at 100; the repository is what would issue the unbounded query. */
    private static function pageSize(int $limit): int
    {
        return max(1, min(PageQuery::MAX_LIMIT, $limit));
    }
}
