<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Plans\CursorPage;
use App\Application\Plans\PageQuery;
use App\Application\Plans\PlanMessageRepository;
use App\Application\Plans\PlanMessageSummary;
use App\Domain\Shared\PageCursor;
use App\Models\PlanMessage;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * create() writes through the PlanMessage model, field by field - PlanMessage is
 * $guarded = ['*'], so ::create() throws MassAssignmentException, the same reasoning CreatePlan's
 * own docblock gives for Plan and PlanMember. id, created_at and updated_at are left for
 * HasUuids and Eloquent's own auto-timestamps to fill, matching CreatePlan's $plan->save() -
 * neither is assigned here.
 *
 * listForPlan() reads through DB::table('plan_messages') rather than the Eloquent model, mirroring
 * EloquentPlanActivityRepository::listForPlan()'s structure: WHERE plan_id = ? AND
 * deleted_at IS NULL, ORDER BY created_at DESC, id DESC, a row-comparison keyset predicate over
 * (created_at, id), a limit+1 over-fetch to answer "is there a next page" without a second count
 * query, and PageCursor::fromKeys() off the last row of the page actually returned.
 *
 * reactions is [] on every summary this class produces - Task 4 is what populates it for real.
 * $viewerId is accepted (the interface requires it) and unused until then.
 */
final readonly class EloquentPlanMessageRepository implements PlanMessageRepository
{
    public function create(string $planId, string $userId, string $body): PlanMessageSummary
    {
        $message = new PlanMessage;
        $message->plan_id = $planId;
        $message->user_id = $userId;
        $message->body = $body;
        $message->save();

        return new PlanMessageSummary(
            id: $message->id,
            planId: $message->plan_id,
            userId: $message->user_id,
            body: $message->body,
            editedAt: null,
            createdAt: $message->created_at->toImmutable(),
            updatedAt: $message->updated_at->toImmutable(),
            reactions: [],
        );
    }

    public function listForPlan(string $planId, string $viewerId, PageQuery $query): CursorPage
    {
        $size = self::pageSize($query->limit);

        $builder = DB::table('plan_messages')
            ->where('plan_id', '=', $planId)
            ->whereNull('deleted_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit($size + 1);

        if ($query->cursor !== null) {
            // Row-comparison form over (created_at, id) - the same device
            // EloquentPlanActivityRepository::listForPlan() uses, for the same reason: it keeps
            // the ORDER BY's own index usable, unlike the equivalent OR form.
            $builder->whereRaw('(created_at, id) < (?, ?)', [
                $query->cursor->timestampForQuery(),
                $query->cursor->id(),
            ]);
        }

        $rows = $builder->get();
        $hasMore = $rows->count() > $size;
        $page = $hasMore ? $rows->slice(0, $size) : $rows;

        // A plain push loop, not $page->values()->map(...)->all(): PHPStan's list-inference rule
        // accepts the former and not the latter for a constructor typed list<PlanMessageSummary>
        // - see EloquentPlanActivityRepository::listForPlan()'s own comment for the measurement.
        $items = [];

        foreach ($page->values() as $row) {
            $items[] = new PlanMessageSummary(
                id: $row->id,
                planId: $row->plan_id,
                userId: $row->user_id,
                body: $row->body,
                editedAt: $row->edited_at === null ? null : Carbon::parse($row->edited_at)->toImmutable(),
                createdAt: Carbon::parse($row->created_at)->toImmutable(),
                updatedAt: Carbon::parse($row->updated_at)->toImmutable(),
                reactions: [],
            );
        }

        $last = $hasMore ? $page->last() : null;
        $nextCursor = $last === null ? null : PageCursor::fromKeys(Carbon::parse($last->created_at)->toImmutable(), $last->id)->toString();

        return new CursorPage($items, $nextCursor);
    }

    /** §6.2 caps a page at 100 - matching every sibling repository's own pageSize(). */
    private static function pageSize(int $limit): int
    {
        return max(1, min(PageQuery::MAX_LIMIT, $limit));
    }
}
