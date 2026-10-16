<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Plans\CursorPage;
use App\Application\Plans\PageQuery;
use App\Application\Plans\PlanContext;
use App\Application\Plans\PlanNotVisible;
use App\Application\Plans\PlanRepository;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanPermissions;
use App\Domain\Plans\PlanStatus;
use App\Domain\Shared\PageCursor;
use App\Models\Plan;
use App\Models\PlanMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Str;
use RuntimeException;

/**
 * The first repository implementation in this codebase. Reads only - see the interface.
 */
final readonly class EloquentPlanRepository implements PlanRepository
{
    public function findVisibleTo(string $planId, string $actorId): PlanContext
    {
        // The shape check is here and not on the route: see PlanNotVisible's docblock for why
        // Route::whereUuid('plan') is the wrong place, and §17.6 item 1 for the measurement
        // that without it `where id = 'not-a-uuid'` against a uuid column is Postgres 22P02 and
        // a 500.
        if (! Str::isUuid($planId) || ! Str::isUuid($actorId)) {
            throw new PlanNotVisible;
        }

        $plan = $this->withViewer($actorId)->whereKey($planId)->first();

        if ($plan === null) {
            throw new PlanNotVisible;
        }

        $context = $this->contextFor($plan);

        if ($context === null) {
            throw new PlanNotVisible;
        }

        return $context;
    }

    public function listForActor(string $actorId, PlanStatus $status, PageQuery $query): CursorPage
    {
        if (! Str::isUuid($actorId)) {
            return new CursorPage([], null);
        }

        // Named $eloquentQuery, not $query: the parameter is now a PageQuery, and this is the
        // Eloquent builder - the two must not share a name.
        $eloquentQuery = $this->withViewer($actorId)
            ->where('plans.status', $status->value)
            // §7.1's `active`-only filter is the thing to get right: without it a user who left
            // or was removed keeps seeing the Plan for ever, because UNIQUE(plan_id, user_id)
            // means their row is never deleted.
            //
            // MemberStatus::Active->value here is a second copy of
            // PlanPermissions::grantsAccess()'s rule, written in SQL because SQL cannot call a
            // policy. The copy is kept honest by the test that drives every MemberStatus case
            // through this method and compares the outcome against grantsAccess() - it fails
            // if the two ever disagree.
            //
            // whereIn('role') applies the same discipline to the role column: a row whose role
            // no MemberRole case recognises cannot become a PlanContext, so it is excluded by
            // the query rather than throwing halfway through building a page.
            ->whereHas('members', fn (Builder $membership) => $membership
                ->where('plan_members.user_id', $actorId)
                ->where('plan_members.status', MemberStatus::Active->value)
                ->whereIn('plan_members.role', self::roleValues()))
            // plans.updated_at is NULLABLE - Blueprint::timestampsTz() declares both of the
            // columns it creates ->nullable() - and this cursor leads on it. Postgres sorts
            // NULLs FIRST on DESC, and a keyset predicate over a NULL evaluates to NULL, so
            // such a row would arrive on page 1 and be dropped from every page after it.
            // Excluding it is consistent rather than intermittent; Eloquent fills both
            // timestamps on every write, so this excludes no row the application can produce,
            // and it is what makes Plan's `@property Carbon $updated_at` true of every row
            // this method returns.
            ->whereNotNull('plans.updated_at')
            ->orderByDesc('plans.updated_at')
            ->orderByDesc('plans.id');

        // $query->cursor is already a validated PageCursor or null - nothing here decodes a raw
        // string. A cursor this API did not issue never reaches this method: PageCursor::
        // fromString() throws for one, and the resources section's ValidCursor rule turns that
        // into a 422 before a PageQuery is ever constructed.
        $cursor = $query->cursor;

        if ($cursor !== null) {
            $eloquentQuery->where(function (Builder $keyset) use ($cursor): void {
                $keyset->where('plans.updated_at', '<', $cursor->timestampForQuery())
                    ->orWhere(function (Builder $tie) use ($cursor): void {
                        $tie->where('plans.updated_at', $cursor->timestampForQuery())
                            ->where('plans.id', '<', $cursor->id());
                    });
            });
        }

        $size = self::pageSize($query->limit);

        // One row more than the page, which is how "is there a next page" is answered without a
        // second count query.
        $rows = $eloquentQuery->limit($size + 1)->get();
        $page = $rows->take($size);

        $items = [];

        foreach ($page as $row) {
            $context = $this->contextFor($row);

            if ($context === null) {
                throw new RuntimeException(
                    "plans row {$row->id} has no resolvable active membership for {$actorId}, "
                    .'which the whereHas filter on this query excludes.'
                );
            }

            $items[] = $context;
        }

        $last = $page->last();

        $nextCursor = $rows->count() > $size && $last !== null
            ? PageCursor::fromKeys($last->updated_at->toImmutable(), $last->id)->toString()
            : null;

        return new CursorPage($items, $nextCursor);
    }

    public function findById(string $planId): ?Plan
    {
        if (! Str::isUuid($planId)) {
            return null;
        }

        return Plan::query()->whereKey($planId)->first();
    }

    public function countActiveMembers(string $planId): int
    {
        if (! Str::isUuid($planId)) {
            return 0;
        }

        return PlanMember::query()
            ->where('plan_id', $planId)
            ->where('status', MemberStatus::Active->value)
            ->count();
    }

    /**
     * plans.*, the actor's own two membership columns as subselects, and §6.1's active member
     * count as a withCount aggregate - one statement, no N+1 over members.
     *
     * The aliases are viewer_role and viewer_status, not role and status: `select plans.*`
     * already carries a `status` column, and a collision would silently overwrite the Plan's
     * own with the membership's.
     *
     * @return Builder<Plan>
     */
    private function withViewer(string $actorId): Builder
    {
        return Plan::query()
            ->select('plans.*')
            ->addSelect([
                'viewer_role' => PlanMember::query()
                    ->select('role')
                    ->whereColumn('plan_members.plan_id', 'plans.id')
                    ->where('plan_members.user_id', $actorId)
                    ->limit(1),
                'viewer_status' => PlanMember::query()
                    ->select('status')
                    ->whereColumn('plan_members.plan_id', 'plans.id')
                    ->where('plan_members.user_id', $actorId)
                    ->limit(1),
            ])
            ->withCount([
                'members as active_member_count' => fn (Builder $members) => $members
                    ->where('status', MemberStatus::Active->value),
            ])
            // withCount adds no cast of its own - QueriesRelationships::withAggregate() casts
            // only withExists(), and to bool - and Postgres returns count() as a bigint that
            // PDO hands back as a string. Without this, memberCount's int type would depend on
            // the driver.
            ->withCasts(['active_member_count' => 'integer']);
    }

    /**
     * Null when the actor has no membership row for this Plan, when the row's role or status is
     * a value no enum case recognises, or when the status does not grant access.
     *
     * That list is this method's own refusal causes, not §5.1's. §5.1 names a malformed id and
     * an unknown Plan as well, and neither ever reaches here - findVisibleTo() refuses both
     * before it calls this method - while the unrecognised-value cause is in no §5.1 list at
     * all, because corrupt data is not a case the spec contemplates. What §5.1 collapses into
     * one answer is the exception; see PlanNotVisible, which is where that argument lives.
     *
     * tryFrom(), not from(), and the conversion is here rather than in an Eloquent cast on the
     * model. Two measurements behind that: Scramble harvests a native enum's CLASS docblock
     * into the published schema description unconditionally, and MemberRole's docblock is six
     * lines of internal rationale - a cast is what would let the enum reach a Resource field
     * and publish it; and a cast turns any unrecognised value into a ValueError during
     * hydration for every reader of the table, including DeleteAccount and the day-30 purge,
     * while plans.status carries no CHECK at all (ConstraintTest asserts the count is 0).
     * Failing closed here keeps the damage inside the one request that asked.
     */
    private function contextFor(Plan $plan): ?PlanContext
    {
        $roleValue = $plan->getAttribute('viewer_role');
        $statusValue = $plan->getAttribute('viewer_status');

        $role = is_string($roleValue) ? MemberRole::tryFrom($roleValue) : null;
        $status = is_string($statusValue) ? MemberStatus::tryFrom($statusValue) : null;

        if ($role === null || $status === null || ! PlanPermissions::grantsAccess($status)) {
            return null;
        }

        $count = $plan->getAttribute('active_member_count');

        if (! is_int($count)) {
            throw new RuntimeException(
                "plans row {$plan->id} was read without withCount('members as active_member_count'), "
                .'so member_count cannot be published.'
            );
        }

        return new PlanContext($plan, $count, $role, $status);
    }

    /** @return list<string> */
    private static function roleValues(): array
    {
        return array_map(fn (MemberRole $role) => $role->value, MemberRole::cases());
    }

    /**
     * §6.2 caps a page at 100. The FormRequest validates `limit` as well; the cap is repeated
     * here because this class is what would issue the unbounded query, and a bound enforced
     * only one layer out is enforced only by whoever remembers to call it that way.
     */
    private static function pageSize(int $limit): int
    {
        return max(1, min(PageQuery::MAX_LIMIT, $limit));
    }
}
