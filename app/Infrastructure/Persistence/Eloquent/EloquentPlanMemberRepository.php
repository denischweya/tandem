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

            // Both checks narrow a type for PHPStan level 8, and both are written as throws
            // rather than coercions on purpose. Registration is the only path that creates a
            // user and it writes the user_profiles row in the same breath, and the query above
            // excludes a NULL joined_at - so neither is reachable through the application.
            // Coercing instead would publish an empty display_name for a real member.
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
            // unrecognised plan_members.role here is a ValueError - a 500 on §8.1's list rather
            // than a member quietly missing from it. plan_members.role is under no CHECK, but
            // every writer in this codebase writes MemberRole::X->value, so the exposure is
            // corrupt data only. Left as the task prescribed it and recorded here rather than
            // narrowed, because nothing asserts either behaviour and a filter no test can show
            // red would be a guard in name only.
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
