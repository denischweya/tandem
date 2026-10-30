<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence\Eloquent;

use App\Application\Plans\CursorPage;
use App\Application\Plans\PageQuery;
use App\Application\Plans\PlanMessageReactionSummary;
use App\Application\Plans\PlanMessageRepository;
use App\Application\Plans\PlanMessageSummary;
use App\Domain\Shared\PageCursor;
use App\Models\PlanMessage;
use App\Models\PlanMessageReaction;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use RuntimeException;

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
 * reactions carries real data on every summary this class produces, from plan_message_reactions
 * via reactionsByMessageId() below - batched per page/row rather than queried once per message,
 * the same "one query, not N+1" discipline EloquentPlanActivityRepository::listForPlan() follows
 * for actor names. create() is the one exception, and deliberately so: a message that was just
 * inserted cannot yet have a reaction, so reactions: [] there is correct, not a placeholder.
 *
 * userDisplayName comes from displayNamesByUserId() below, which mirrors
 * EloquentPlanActivityRepository::listForPlan()'s own batched user_profiles lookup exactly: the
 * page's DISTINCT non-null user_ids in one whereIn, never a lookup per row. Unlike reactions it
 * is populated on EVERY path including create() and update(), because both of those summaries
 * ARE serialised to a client (201 on POST, 200 on PATCH) and a null name on a message its author
 * wrote a millisecond ago would read as an anonymised sender rather than the one who just sent
 * it. ADR 0012 is why the name can legitimately be null at all.
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

        // toSummary(), not a fourth hand-written construction of this object: editedAt is
        // $message->edited_at (null on a row that was just inserted, so the literal null this
        // used to pass is what toSummary() already computes), and routing through it is what
        // keeps the display-name lookup in ONE place rather than three.
        return self::toSummary($message);
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

        // Baseline discipline (see EloquentPlanActivityRepository::listForPlan()'s own
        // comment for actor names): one batched query over every message id on THIS page,
        // not one plan_message_reactions lookup per row.
        $reactionsByMessageId = self::reactionsByMessageId($page->pluck('id')->all(), $viewerId);

        // The same batching, for the same reason, over the other dimension: the page's distinct
        // non-null senders in one user_profiles query. ->filter() drops the nulls an anonymised
        // sender leaves (ADR 0012) before ->unique(), so a page of twenty messages from three
        // people asks for three ids, not twenty - and a page of entirely anonymised messages
        // asks nothing at all. Mirrors EloquentPlanActivityRepository::listForPlan()'s own
        // actor-name lookup line for line.
        $displayNames = self::displayNamesByUserId($page->pluck('user_id')->filter()->unique()->values()->all());

        // A plain push loop, not $page->values()->map(...)->all(): PHPStan's list-inference rule
        // accepts the former and not the latter for a constructor typed list<PlanMessageSummary>
        // - see EloquentPlanActivityRepository::listForPlan()'s own comment for the measurement.
        $items = [];

        foreach ($page->values() as $row) {
            $items[] = new PlanMessageSummary(
                id: $row->id,
                planId: $row->plan_id,
                userId: $row->user_id,
                // Three states, not two, exactly as PlanActivitySummary::$actorName's own
                // comment in the activity repository sets out: no user_id (anonymised, null), a
                // user_id with a profile (the name), and a user_id whose user_profiles row is
                // gone - which the day-30 hard purge produces, because user_profiles.user_id
                // CASCADEs off users while this column only SET NULLs. The `?? null` renders
                // the third like the first, and is held open by the test rather than by the
                // analyser: reading $displayNames[$row->user_id] directly is an undefined-key
                // E_WARNING that HandleExceptions promotes to an ErrorException at runtime.
                userDisplayName: $row->user_id === null ? null : ($displayNames[$row->user_id] ?? null),
                body: $row->body,
                editedAt: $row->edited_at === null ? null : Carbon::parse($row->edited_at)->toImmutable(),
                createdAt: Carbon::parse($row->created_at)->toImmutable(),
                updatedAt: Carbon::parse($row->updated_at)->toImmutable(),
                reactions: $reactionsByMessageId[$row->id] ?? [],
            );
        }

        $last = $hasMore ? $page->last() : null;
        $nextCursor = $last === null ? null : PageCursor::fromKeys(Carbon::parse($last->created_at)->toImmutable(), $last->id)->toString();

        return new CursorPage($items, $nextCursor);
    }

    /**
     * Str::isUuid() first, for the same reason EloquentPlanRepository's and
     * EloquentInviteRepository's own finders give: `where id = 'not-a-uuid'` against a uuid
     * column is Postgres 22P02, surfacing as a 500 rather than the 404 PlanMessageNotFound
     * already owns. PlanMessage::find() is fine for the rest - one row by primary key for an
     * authorship check is not the keyset-list query EloquentPlanActivityRepository's own
     * comment protects.
     *
     * Null for a soft-deleted row too: a caller editing or deleting an already-gone message
     * should see the same PlanMessageNotFound a never-existed id gives, not resurrect it by
     * editing a row listForPlan() has already dropped out of every list.
     *
     * reactions carries real reaction and count values, from the one-row form of the same
     * batched query listForPlan() uses - but reactedByViewer is always false, because this
     * method has no viewer to compare against. See this method's own interface docblock for
     * why that is inert rather than wrong: nothing that calls find() ever serialises its
     * result to a client.
     */
    public function find(string $messageId): ?PlanMessageSummary
    {
        if (! Str::isUuid($messageId)) {
            return null;
        }

        $message = PlanMessage::find($messageId);

        if ($message === null || $message->deleted_at !== null) {
            return null;
        }

        $reactions = self::reactionsByMessageId([$message->id], null)[$message->id] ?? [];

        return self::toSummary($message, $reactions);
    }

    /**
     * PlanMessage::find(), not findOrFail(): tests/Feature/Security/RefusalSeamTest.php's
     * "leaves every refusal status to bootstrap/app.php" forbids findOrFail() outright -
     * ModelNotFoundException maps to a pretty-printed 404 that leaks the model class and the
     * id, a second body for a refusal PlanMessageNotFound already owns. A null here means the
     * row vanished between EditMessage's own find() and this call - a concurrent delete -
     * which is a genuine, unexpected failure, not a 404 silently handed back for a message
     * that existed moments ago.
     */
    public function update(string $messageId, string $body): PlanMessageSummary
    {
        $message = PlanMessage::find($messageId);

        if ($message === null) {
            throw new RuntimeException("plan_messages row {$messageId} vanished between EditMessage's find() and update().");
        }

        $message->body = $body;
        $message->edited_at = Carbon::now();
        $message->save();

        return self::toSummary($message);
    }

    /** See update()'s own comment for why this is find(), not softDelete()'s former findOrFail(). */
    public function softDelete(string $messageId): void
    {
        $message = PlanMessage::find($messageId);

        if ($message === null) {
            throw new RuntimeException("plan_messages row {$messageId} vanished between DeleteMessage's find() and softDelete().");
        }

        $message->deleted_at = Carbon::now();
        $message->save();
    }

    /**
     * The one place a PlanMessage model becomes a PlanMessageSummary - create(), find() and
     * update() all route through it. $reactions defaults to [], which is what create() and
     * update() both pass implicitly: a just-inserted row cannot have one, and for update()
     * Task 4's brief scoped real reaction population to listForPlan() and find() only, so
     * EditMessage's own HTTP response keeps its shipped reactions: [].
     *
     * The display-name lookup is NOT skipped on those paths, unlike reactions - see this class's
     * own docblock for why. It is the one-row form of listForPlan()'s batched query, through the
     * same helper, so there is one definition of "how a sender's name is resolved".
     *
     * @param  list<PlanMessageReactionSummary>  $reactions
     */
    private static function toSummary(PlanMessage $message, array $reactions = []): PlanMessageSummary
    {
        $displayNames = self::displayNamesByUserId($message->user_id === null ? [] : [$message->user_id]);

        return new PlanMessageSummary(
            id: $message->id,
            planId: $message->plan_id,
            userId: $message->user_id,
            // Same three states as listForPlan()'s own construction - see the comment there.
            userDisplayName: $message->user_id === null ? null : ($displayNames[$message->user_id] ?? null),
            body: $message->body,
            editedAt: $message->edited_at?->toImmutable(),
            createdAt: $message->created_at->toImmutable(),
            updatedAt: $message->updated_at->toImmutable(),
            reactions: $reactions,
        );
    }

    /**
     * Field-by-field through `new PlanMessageReaction`, not ::create(): PlanMessageReaction is
     * $guarded = ['*'], the same reasoning every other write in this class gives for
     * PlanMessage. created_at is stamped here explicitly rather than left to Eloquent's own
     * timestamps: the model declares $timestamps = false (the table carries no updated_at),
     * so nothing else would fill it and the column is NOT NULL.
     *
     * The catch is the idempotent-add itself, not a race-prone exists() pre-check: a second
     * reaction with the same (message_id, user_id, reaction) triple hits the table's own
     * UNIQUE constraint, and losing that race is success, not failure - the row the caller
     * wanted is already there.
     *
     * THE SAVE SITS INSIDE DB::transaction(), AND THE CATCH SITS AROUND IT - AcceptInvite's own
     * docblock measures why, and the same measurement reproduces here: a bare
     * try { $row->save(); } catch (...) {} with no transaction of its own leaves Postgres
     * itself (not just this object) in an aborted state once the INSERT violates the
     * constraint - 25P02, "current transaction is aborted, commands ignored until end of
     * transaction block" - for every statement after it that shares the same still-open
     * transaction. Production autocommits a bare statement, so that risk is invisible there,
     * but every test in this suite runs inside RefreshDatabase's own enclosing transaction,
     * where it is not: measured directly by calling this method twice in one test without
     * this wrapper - the first add succeeds, and the second's own save() throws the real
     * UniqueConstraintViolationException correctly, but the very next query of ANY kind in
     * that same test then fails with 25P02, not the exception it caught. Wrapping the save in
     * its own DB::transaction() gives Laravel's nested-transaction support a SAVEPOINT to roll
     * back to instead - which only exists because RefreshDatabase's own transaction is already
     * open - so the rollback undoes just this one statement, and the connection (real or
     * test) is reusable immediately after, exactly as AcceptInvite's docblock states for its
     * own identical catch.
     */
    public function addReaction(string $messageId, string $userId, string $reaction): void
    {
        try {
            DB::transaction(function () use ($messageId, $userId, $reaction): void {
                $row = new PlanMessageReaction;
                $row->message_id = $messageId;
                $row->user_id = $userId;
                $row->reaction = $reaction;
                $row->created_at = Carbon::now();
                $row->save();
            });
        } catch (UniqueConstraintViolationException) {
            // Already there - see this method's own docblock.
        }
    }

    /**
     * A plain delete by the matching triple. Zero rows deleted - no such reaction, already
     * removed by an earlier call, or an unknown $messageId - is also a no-op success, matching
     * this method's own interface docblock.
     */
    public function removeReaction(string $messageId, string $userId, string $reaction): void
    {
        DB::table('plan_message_reactions')
            ->where('message_id', '=', $messageId)
            ->where('user_id', '=', $userId)
            ->where('reaction', '=', $reaction)
            ->delete();
    }

    /**
     * Every (message, reaction) group for $messageIds, in one batched query - the same
     * "one query, not N+1" discipline EloquentPlanActivityRepository::listForPlan() follows
     * for actor names, rather than a plan_message_reactions lookup per message.
     *
     * $viewerId is nullable because find() has no viewer of its own (see find()'s own
     * docblock) and passes null - every group's reactedByViewer is then false, which is inert
     * rather than wrong, because nothing that calls find() ever serialises its result to a
     * client.
     *
     * Ordered by created_at so that PHP's own array key order - which this method relies on
     * to build each message's reaction list - reflects the order reactions were first added,
     * rather than an incidental one.
     *
     * @param  array<int, string>  $messageIds
     * @return array<string, list<PlanMessageReactionSummary>>
     */
    private static function reactionsByMessageId(array $messageIds, ?string $viewerId): array
    {
        if ($messageIds === []) {
            return [];
        }

        $rows = DB::table('plan_message_reactions')
            ->whereIn('message_id', $messageIds)
            ->orderBy('created_at')
            ->get(['message_id', 'user_id', 'reaction']);

        // message_id => reaction => ['count' => int, 'userIds' => list<string>]. A plain
        // nested array, not a Collection::groupBy() chain - the shape this method has to
        // produce (list<PlanMessageReactionSummary> per message) is what the comment on
        // $items in listForPlan() already explains PHPStan's list-inference rule wants, and a
        // plain array built by a push loop is the form every sibling repository already uses
        // to satisfy it.
        $grouped = [];

        foreach ($rows as $row) {
            $grouped[$row->message_id][$row->reaction]['count'] ??= 0;
            $grouped[$row->message_id][$row->reaction]['count']++;
            $grouped[$row->message_id][$row->reaction]['userIds'][] = $row->user_id;
        }

        $summaries = [];

        foreach ($grouped as $messageId => $byReaction) {
            $list = [];

            foreach ($byReaction as $reaction => $group) {
                $list[] = new PlanMessageReactionSummary(
                    reaction: $reaction,
                    count: $group['count'],
                    reactedByViewer: $viewerId !== null && in_array($viewerId, $group['userIds'], true),
                );
            }

            $summaries[$messageId] = $list;
        }

        return $summaries;
    }

    /**
     * display_name for each of $userIds, in one query - the same batched user_profiles lookup
     * EloquentPlanActivityRepository::listForPlan() performs for actor names, lifted into a
     * named helper here because four call sites share it (listForPlan() and the three paths
     * through toSummary()) rather than the one that file has.
     *
     * $userIds must already be non-null and distinct: listForPlan() does that with
     * ->filter()->unique() over the page, and toSummary() passes nothing or one id. Keeping the
     * filtering at the call sites rather than here is what lets the empty-array early return
     * stand for "nothing to ask" in both cases - a page of entirely anonymised messages issues
     * no query at all.
     *
     * @param  array<int, string>  $userIds
     * @return array<string, string>
     */
    private static function displayNamesByUserId(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $names = [];

        foreach (DB::table('user_profiles')->whereIn('user_id', $userIds)->get(['user_id', 'display_name']) as $row) {
            $names[(string) $row->user_id] = (string) $row->display_name;
        }

        return $names;
    }

    /** §6.2 caps a page at 100 - matching every sibling repository's own pageSize(). */
    private static function pageSize(int $limit): int
    {
        return max(1, min(PageQuery::MAX_LIMIT, $limit));
    }
}
