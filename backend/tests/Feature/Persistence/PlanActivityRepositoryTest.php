<?php

declare(strict_types=1);

use App\Application\Plans\PageQuery;
use App\Application\Plans\PlanActivityRepository;
use App\Application\Plans\PlanActivitySummary;
use App\Domain\Shared\PageCursor;
use App\Infrastructure\Persistence\Eloquent\EloquentPlanActivityRepository;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('binds the activity repository interface to its Eloquent implementation', function (): void {
    expect(app(PlanActivityRepository::class))->toBeInstanceOf(EloquentPlanActivityRepository::class);
});

it('lists a Plan\'s activity newest first, scoped to that Plan', function (): void {
    $plan = Plan::factory()->create();
    $elsewhere = Plan::factory()->create();
    $actor = User::factory()->hasProfile(['display_name' => 'Ada Lovelace'])->create();

    PlanActivity::factory()->create(['plan_id' => $elsewhere->id, 'created_at' => '2026-10-05 09:00:00+00:00']);
    $older = PlanActivity::factory()->create(['plan_id' => $plan->id, 'actor_id' => $actor->id, 'created_at' => '2026-10-01 09:00:00+00:00']);
    $newer = PlanActivity::factory()->create(['plan_id' => $plan->id, 'actor_id' => $actor->id, 'created_at' => '2026-10-03 09:00:00+00:00']);

    $page = app(PlanActivityRepository::class)->listForPlan($plan->id, new PageQuery(25, null));

    expect(array_map(fn (PlanActivitySummary $a) => $a->id, $page->items))->toBe([$newer->id, $older->id]);
    expect($page->items[0]->actorName)->toBe('Ada Lovelace');
});

it('renders actor_name null for an anonymised row, with no actor_id at all', function (): void {
    $plan = Plan::factory()->create();
    PlanActivity::factory()->create(['plan_id' => $plan->id, 'actor_id' => null]);

    $page = app(PlanActivityRepository::class)->listForPlan($plan->id, new PageQuery(25, null));

    expect($page->items[0]->actorId)->toBeNull();
    expect($page->items[0]->actorName)->toBeNull();
});

it('renders actor_name null for a non-null actor_id with no matching user_profiles row', function (): void {
    // The third actor state, baseline §11.3 item 2. plan_activity.actor_id is deliberately NOT
    // a foreign key - migration 2026_10_06_000200_create_plan_tables.php's plan_activity
    // block has `$table->uuid('actor_id')->nullable();`, with a single-column index on it and
    // no references() anywhere - so a row can name an actor that has no users row and no
    // user_profiles row at all, and seeding it needs neither a soft delete nor the day-30 purge.
    //
    // The id is a literal rather than (string) Str::uuid() on purpose: the second break step
    // for this guard quotes PHP's own `Undefined array key "..."` text, which contains this
    // value, so it has to be identical on every machine and every run. The literal also
    // overrides PlanActivityFactory's 'actor_id' => User::factory() default, and a factory
    // instance left in place by an override is never resolved, so no users row is created
    // for it.
    $plan = Plan::factory()->create();
    PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => '0192f0a1-0000-7000-8000-00000000dead',
    ]);

    $page = app(PlanActivityRepository::class)->listForPlan($plan->id, new PageQuery(25, null));

    expect($page->items[0]->actorId)->toBe(
        '0192f0a1-0000-7000-8000-00000000dead',
        'the actor_id is published even when no profile resolves: the id is in the row, the '
        .'name is a lookup that found nothing'
    );
    expect($page->items[0]->actorName)->toBeNull(
        'the third actor state - a non-null actor_id with no user_profiles row - must render '
        .'null, never a placeholder name the API never stored'
    );
});

it('maps actor_id, actor_name, subject_type and subject_id without transposing any of them', function (): void {
    // PlanActivitySummary's constructor has three invisible same-typed swap pairs -
    // actorId<->actorName, subjectType<->subjectId, actorId<->subjectId - none of which the
    // other cases in this file disambiguate: PlanActivityFactory's definition() sets neither
    // subject_type nor subject_id, so every other fixture here leaves both null, and the
    // actor_id case above never sets subject_id either. A transposition touching subject_id
    // would therefore pass with a null on both sides - exactly the shape that let Task 10 ship
    // an undetected swap of two ?Carbon fields. This fixture gives every one of the five
    // string-shaped fields a value no other field in the row could be confused with: a uuid
    // and a name (pair 1), a type string and a different uuid (pair 2), and two DIFFERENT
    // uuids for actor_id and subject_id (pair 3, the one the brief's own D.1 fixture also
    // left unable to distinguish, reusing the same value for both).
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile(['display_name' => 'Grace Hopper'])->create();

    PlanActivity::factory()->create([
        'plan_id' => $plan->id,
        'actor_id' => $actor->id,
        'activity_type' => 'member.invited',
        'subject_type' => 'PlanInvite',
        'subject_id' => '0192f0a1-0000-7000-8000-00000000beef',
    ]);

    $page = app(PlanActivityRepository::class)->listForPlan($plan->id, new PageQuery(25, null));
    $summary = $page->items[0];

    expect($summary->actorId)->toBe(
        $actor->id,
        'actorId must come from the row actor_id, not from subject_id or the resolved actor_name'
    );
    expect($summary->actorName)->toBe(
        'Grace Hopper',
        'actorName must come from the resolved user_profiles.display_name, not from actor_id itself'
    );
    expect($summary->subjectType)->toBe(
        'PlanInvite',
        'subjectType must come from the row subject_type, not from subject_id'
    );
    expect($summary->subjectId)->toBe(
        '0192f0a1-0000-7000-8000-00000000beef',
        'subjectId must come from the row subject_id, not from subject_type or actor_id'
    );
});

it('paginates with the DESC cursor over (created_at, id)', function (): void {
    $plan = Plan::factory()->create();
    foreach (range(1, 3) as $i) {
        // actor_id is pinned to null here rather than left to PlanActivityFactory's
        // 'actor_id' => User::factory() default. UserFactory writes no user_profiles row
        // (->hasProfile() is a call site, never a factory default), so the default would put
        // three rows in the third actor state inside a case about cursors - and it would spread
        // the guard's second break step across two cases, the second of which would carry a
        // random uuid in its failure text. This case asserts nothing about names.
        PlanActivity::factory()->create([
            'plan_id' => $plan->id,
            'actor_id' => null,
            'created_at' => "2026-10-0{$i} 09:00:00+00:00",
        ]);
    }

    $repository = app(PlanActivityRepository::class);
    $firstPage = $repository->listForPlan($plan->id, new PageQuery(2, null));

    expect($firstPage->nextCursor)->not->toBeNull();

    // CursorPage::$nextCursor is ?string and Pest's ->not->toBeNull() above does not narrow
    // that for the analyser - it is a runtime assertion PHPStan cannot see through. Real
    // control-flow narrowing, not a cast and not an annotation, matching the shipped precedent
    // in PlanRepositoryTest and PlanMemberRepositoryTest.
    $cursor = $firstPage->nextCursor;

    if ($cursor === null) {
        throw new RuntimeException('page 1 of 3 must carry a next cursor.');
    }

    $secondPage = $repository->listForPlan($plan->id, new PageQuery(2, PageCursor::fromString($cursor)));

    expect($secondPage->items)->toHaveCount(1);
    expect($secondPage->nextCursor)->toBeNull();
});

it('clamps a limit of 0 up to one row rather than returning an empty page', function (): void {
    // pageSize()'s max(1, ...) arm, which every other case in this file misses: they pass 1, 2
    // or 25. A limit of 0 is a value this method can really be handed - the global
    // ConvertEmptyStringsToNull middleware turns `?limit=` into a present null, and (int) null
    // is 0 - so without the arm, min(MAX_LIMIT, 0) makes this limit(1) with slice(0, 0): an
    // empty page AND a null cursor for a Plan that does have activity, which a client reads as
    // "no activity" rather than as a clamp. Mirrors PlanMemberRepositoryTest's own
    // "clamps a limit of 0 up to one row rather than returning an empty page" case.
    $plan = Plan::factory()->create();
    PlanActivity::factory()->create(['plan_id' => $plan->id, 'actor_id' => null, 'created_at' => '2026-10-01 09:00:00+00:00']);
    PlanActivity::factory()->create(['plan_id' => $plan->id, 'actor_id' => null, 'created_at' => '2026-10-02 09:00:00+00:00']);

    $page = app(PlanActivityRepository::class)->listForPlan($plan->id, new PageQuery(0, null));

    expect($page->items)->toHaveCount(1);
    // The cursor matters as much as the row: a page of one with no way forward is still a dead
    // end for the other row. CursorPage's own docblock says the cursor is non-null exactly when
    // the repository read one row more than the page size - this is the first case in this file
    // where that claim is checked against a limit the clamp has to rewrite, rather than against
    // a limit that was already a real page size.
    expect($page->nextCursor)->not->toBeNull();
});

it('resolves actor_name for a page of distinct actors in two queries total, not an N+1', function (): void {
    // Baseline §11.3: the batched whereIn, not a per-row lookup. A page with only ONE actor
    // would pass a query-count assertion by accident even with the lookup moved inside the
    // per-row loop, because one row needs one lookup either way - two DISTINCT actors is the
    // minimum fixture that actually separates "one query total" from "one query per row".
    // Instrument copied from PlanMemberRepositoryTest's own
    // "reads a page of members in exactly one query" case, not invented: register DB::listen
    // after the fixture exists, and assert the row count before the query count, so the query
    // count cannot pass by matching an empty result.
    $plan = Plan::factory()->create();
    $first = User::factory()->hasProfile(['display_name' => 'First Actor'])->create();
    $second = User::factory()->hasProfile(['display_name' => 'Second Actor'])->create();

    PlanActivity::factory()->create(['plan_id' => $plan->id, 'actor_id' => $first->id, 'created_at' => '2026-10-01 09:00:00+00:00']);
    PlanActivity::factory()->create(['plan_id' => $plan->id, 'actor_id' => $second->id, 'created_at' => '2026-10-02 09:00:00+00:00']);

    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    $page = app(PlanActivityRepository::class)->listForPlan($plan->id, new PageQuery(25, null));

    expect($page->items)->toHaveCount(2);
    expect($queries)->toHaveCount(2);
});

it('reads the keyset page with the row-comparison predicate, not the OR form', function (): void {
    // D-R5 / baseline Q5b vs Q5: the OR form reads 74 rows to return 26 and loses the ordered
    // index scan; both forms return the same rows, so only the SQL text itself can tell them
    // apart. Instrument copied from PlanMemberRepositoryTest's own
    // "caps the member page size at PageQuery::MAX_LIMIT" case (a toContain on SQL shape for a
    // property a row count cannot see), aimed at the predicate instead of the limit value.
    $plan = Plan::factory()->create();
    PlanActivity::factory()->create(['plan_id' => $plan->id, 'actor_id' => null, 'created_at' => '2026-10-01 09:00:00+00:00']);
    PlanActivity::factory()->create(['plan_id' => $plan->id, 'actor_id' => null, 'created_at' => '2026-10-02 09:00:00+00:00']);

    $firstPage = app(PlanActivityRepository::class)->listForPlan($plan->id, new PageQuery(1, null));
    $cursor = $firstPage->nextCursor;

    if ($cursor === null) {
        throw new RuntimeException('fixture must produce a next cursor to exercise the keyset predicate.');
    }

    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    app(PlanActivityRepository::class)->listForPlan($plan->id, new PageQuery(1, PageCursor::fromString($cursor)));

    expect(implode(' ', $queries))->toContain('(created_at, id) <');
});
