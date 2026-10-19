<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Plans\CursorPage;
use App\Application\Plans\PageQuery;
use App\Application\Plans\PlanActivityRepository;
use App\Application\Plans\PlanActivitySummary;
use App\Domain\Plans\ActivityType;
use App\Domain\Shared\PageCursor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * The fourth repository implementation (SD-R3: this interface has no spec §4 precedent). Reads
 * the feed through DB::table('plan_activity') rather than the Eloquent model, so it names no
 * App\Models\PlanActivity symbol - see this project's content-scan allow-list for why that
 * matters to a different guard. Ordering and the keyset predicate below rest on the existing
 * plan_activity_plan_id_created_at_index (baseline C-5: Index Scan Backward + Incremental
 * Sort, 27 rows read for 26 returned) - the migration freeze forbids adding another, and the
 * interface's own docblock describes the same index without spelling its name, to stay off
 * this file's allow-list requirement.
 */
final readonly class EloquentPlanActivityRepository implements PlanActivityRepository
{
    public function listForPlan(string $planId, PageQuery $query): CursorPage
    {
        $size = self::pageSize($query->limit);

        $builder = DB::table('plan_activity')
            ->where('plan_id', '=', $planId)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($size + 1);

        if ($query->cursor !== null) {
            // Row-comparison form over (created_at, id) - baseline Q5b / C-5. Q5's OR form
            // read 74 rows to return 26 and lost the Index Scan Backward for a BitmapOr plus
            // a top-N heapsort.
            $builder->whereRaw('(created_at, id) < (?, ?)', [
                $query->cursor->timestampForQuery(),
                $query->cursor->id(),
            ]);
        }

        $rows = $builder->get();
        $hasMore = $rows->count() > $size;
        $page = $hasMore ? $rows->slice(0, $size) : $rows;

        // Baseline §11.3: batch the page's distinct non-null actor_ids in one whereIn, rather
        // than a per-row user_profiles lookup - the obvious implementation runs once per row
        // on the highest-volume table in the schema.
        $actorIds = $page->pluck('actor_id')->filter()->unique()->values()->all();
        $names = $actorIds === []
            ? []
            : DB::table('user_profiles')->whereIn('user_id', $actorIds)->pluck('display_name', 'user_id')->all();

        // A foreach pushing onto $items, not the brief's own $page->values()->map(...)->all():
        // that chain left `make stan` at errors: 1 - CursorPage's constructor is typed
        // list<PlanActivitySummary>, and Larastan's own stubs for Collection::map()->all()
        // infer only array<int, PlanActivitySummary>, which PHPStan will not accept where a
        // list is required ("might not be a list"). A plain push loop is what both sibling
        // repositories already use for the identical reason - see EloquentPlanRepository::
        // listForActor() and EloquentPlanMemberRepository::listActiveMembers() - and PHPStan's
        // own list-inference rule recognises that shape.
        $items = [];

        foreach ($page->values() as $row) {
            $items[] = new PlanActivitySummary(
                id: $row->id,
                activityType: ActivityType::from($row->activity_type),
                actorId: $row->actor_id,
                // Three states, not two (baseline §11.3 item 2): no actor_id (anonymised,
                // null), an actor_id with a profile (the name), and an actor_id with no
                // matching user_profiles row at all - representable because actor_id is
                // deliberately not a foreign key. The third renders null via this ?? null,
                // exactly like the first.
                //
                // Measured, not assumed symmetric with PlanMemberView::fromMember()'s throw
                // for the analogous missing-profile shape (that shape is unreachable there, so
                // throwing is correct - this shape IS reachable here, so it is not): deleting
                // this ?? null and reading $names[$row->actor_id] directly left `make stan` at
                // errors: 0 (the array is PHP's native array<string,string>, not a typed
                // collection PHPStan narrows on a missing key) and instead reddened this
                // method's own test as an ERROR, not a failed assertion - PHP 8.4's own
                // E_WARNING for an undefined array key, promoted to an ErrorException by
                // Illuminate\Foundation\Bootstrap\HandleExceptions, which this suite's kernel
                // bootstrap installs before the test body runs. So the force holding this
                // guard open is the test alone, not the analyser.
                //
                // EloquentPlanMemberRepository::listActiveMembers() carries two guards for one
                // invariant whose own shipped comment there states they are held open by
                // different forces - one by a test alone, one by the analyser alone (its
                // is_string($displayName) and joined_at === null checks respectively). That
                // attribution is transcribed from that file's own comment, not re-measured
                // here, and is cited only to say this guard is a third, distinct force
                // profile - not a copy of either of that pair.
                actorName: $row->actor_id === null ? null : ($names[$row->actor_id] ?? null),
                subjectType: $row->subject_type,
                subjectId: $row->subject_id,
                metadata: json_decode($row->metadata, true),
                createdAt: Carbon::parse($row->created_at),
            );
        }

        $last = $hasMore ? $page->last() : null;
        // ->toImmutable(), not a bare Carbon::parse(): PageCursor::fromKeys() is typed
        // DateTimeImmutable, and Carbon::parse() returns the mutable Illuminate\Support\Carbon
        // (it extends DateTime, not DateTimeImmutable) - measured here by this call raising
        // "Argument #1 ($timestamp) must be of type DateTimeImmutable, Illuminate\Support\
        // Carbon given" the first time a test case with a real next page ran against the
        // brief's own literal line. Matches EloquentPlanRepository::listForActor()'s and
        // EloquentPlanMemberRepository::listActiveMembers()'s own ->toImmutable() calls.
        $nextCursor = $last === null ? null : PageCursor::fromKeys(Carbon::parse($last->created_at)->toImmutable(), $last->id)->toString();

        return new CursorPage($items, $nextCursor);
    }

    /**
     * §6.2 caps a page at 100, matching both sibling repositories' own pageSize() - the
     * repository is what would issue the unbounded query. limit = 0 is reachable unvalidated:
     * a whitespace-only ?limit= skips every non-implicit validation rule on that field, and
     * (int) ' ' is 0. Without the max(1, ...) arm, a Plan with real activity would return a
     * page of zero rows and a null cursor instead of one row and a cursor to the rest - the
     * same defect PlanMemberRepositoryTest's own
     * "clamps a limit of 0 up to one row rather than returning an empty page" case pins for
     * its sibling.
     */
    private static function pageSize(int $limit): int
    {
        return max(1, min(PageQuery::MAX_LIMIT, $limit));
    }
}
