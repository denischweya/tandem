<?php

declare(strict_types=1);

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
use App\Infrastructure\Persistence\Eloquent\EloquentPlanRepository;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('binds the interface to the Eloquent implementation', function (): void {
    expect(app(PlanRepository::class))->toBeInstanceOf(EloquentPlanRepository::class);
});

it('resolves a Plan, the actor role and status, and the active member count', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id, 'title' => 'Hike']);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00');
    PlanMember::factory()->active()->create(['plan_id' => $plan->id]);
    // Two rows that must NOT be counted: §6.1's member_count "counts status = active rows
    // only, matching §8.1's list".
    PlanMember::factory()->create(['plan_id' => $plan->id]);
    PlanMember::factory()->left()->create(['plan_id' => $plan->id]);

    $context = app(PlanRepository::class)->findVisibleTo($plan->id, $owner->id);

    expect($context->plan->title)->toBe('Hike');
    expect($context->role)->toBe(MemberRole::Owner);
    expect($context->status)->toBe(MemberStatus::Active);
    expect($context->memberCount)->toBe(2);
});

it('is visible exactly when PlanPermissions grants access, for every MemberStatus case', function (): void {
    // The anti-drift mechanism for findVisibleTo: the repository calls grantsAccess(), and this
    // drives every case of the enum through it rather than sampling the interesting one. Adding
    // a MemberStatus case without deciding about it fails here.
    $repository = app(PlanRepository::class);

    foreach (MemberStatus::cases() as $status) {
        $plan = Plan::factory()->create();
        $actor = User::factory()->hasProfile()->create();

        PlanMember::factory()->create([
            'plan_id' => $plan->id,
            'user_id' => $actor->id,
            'status' => $status->value,
            'joined_at' => now(),
        ]);

        $visible = true;

        try {
            $repository->findVisibleTo($plan->id, $actor->id);
        } catch (PlanNotVisible) {
            $visible = false;
        }

        expect($visible)->toBe(PlanPermissions::grantsAccess($status), $status->value);
    }
});

it('lists a Plan exactly when PlanPermissions grants access, for every MemberStatus case', function (): void {
    // The same comparison for the list, because the list's status filter is SQL and cannot call
    // the policy. Without this test the two copies of the rule are free to disagree.
    $repository = app(PlanRepository::class);

    foreach (MemberStatus::cases() as $status) {
        $plan = Plan::factory()->create();
        $actor = User::factory()->hasProfile()->create();

        PlanMember::factory()->create([
            'plan_id' => $plan->id,
            'user_id' => $actor->id,
            'status' => $status->value,
            'joined_at' => now(),
        ]);

        $page = $repository->listForActor($actor->id, PlanStatus::Active, new PageQuery(25, null));

        expect($page->items !== [])->toBe(PlanPermissions::grantsAccess($status), $status->value);
    }
});

it('refuses a malformed plan id without reaching Postgres', function (): void {
    // §17.6 item 1. Without the shape check this is SQLSTATE[22P02] and a 500, which is a
    // failure rather than a wrong answer - and the 404 body has to be the same one a real
    // Plan's refusal uses, which is why the check is here and not Route::whereUuid().
    $actor = User::factory()->hasProfile()->create();

    expect(fn () => app(PlanRepository::class)->findVisibleTo('not-a-uuid', $actor->id))
        ->toThrow(PlanNotVisible::class);
});

it('refuses a malformed actor id on both read paths, and reaches Postgres on neither', function (): void {
    // The $actorId twin of 'refuses a malformed plan id without reaching Postgres', which
    // asserts only the $planId half. Both methods put the raw actor string in front of
    // plan_members.user_id - findVisibleTo() through withViewer()'s two subselects,
    // listForActor() through its whereHas - so with either shape check gone this is
    // SQLSTATE[22P02] and a 500 where a 404 or an empty page belongs.
    //
    // Two guards in two methods, and each was mutation-proved on its own: a single mutation
    // spanning both would show only that one of them fires.
    $plan = Plan::factory()->create();

    expect(fn () => app(PlanRepository::class)->findVisibleTo($plan->id, 'not-a-uuid'))
        ->toThrow(PlanNotVisible::class);

    // No exception on this path, deliberately: listForActor() has no 404 and no 403, the result
    // set is the authorization, so a malformed actor is an empty page rather than a refusal.
    $page = app(PlanRepository::class)->listForActor('not-a-uuid', PlanStatus::Active, new PageQuery(25, null));

    expect($page->items)->toBe([]);
    expect($page->nextCursor)->toBeNull();
});

it('refuses a Plan that does not exist with the same exception', function (): void {
    $actor = User::factory()->hasProfile()->create();

    expect(fn () => app(PlanRepository::class)->findVisibleTo('019a0b7e-7c2a-7f3e-8b10-2f6c4d5e6a7b', $actor->id))
        ->toThrow(PlanNotVisible::class);
});

it('refuses an actor who holds no plan_members row, and omits the Plan from their list', function (): void {
    // §5.1's third case, and the one this whole seam exists for: a persisted user, a real Plan
    // with real members, and no row joining the two.
    //
    // Both answers are asserted because two independent mechanisms produce them, and neither
    // mutation reds the other half. findVisibleTo() refuses because withViewer()'s
    // viewer_role/viewer_status subselects return NULL for a non-member and contextFor()'s two
    // is_string() ternaries fall to their `: null` arm - give those arms a Member/Active
    // default instead and a persisted non-member receives a full PlanContext for a Plan they
    // have no relationship to. The list omits the Plan for a different reason entirely: the
    // whereHas filter on plan_members.user_id, which the ternaries never see.
    //
    // Expect the shape of that second proof, so a re-runner does not read it as a broken step:
    // dropping the whereHas user_id filter reds this assertion as an ERROR, not as a
    // `Failed asserting ...` diff. The row then reaches contextFor(), which cannot resolve a
    // membership for THIS actor, and listForActor() raises its RuntimeException. SQL wider than
    // the policy is a loud 500 by design - never a silent leak - so the error IS the guard
    // working.
    $plan = Plan::factory()->create(['title' => 'Secret']);
    PlanMember::factory()->active()->create(['plan_id' => $plan->id]);

    $stranger = User::factory()->hasProfile()->create();
    $repository = app(PlanRepository::class);

    expect(fn () => $repository->findVisibleTo($plan->id, $stranger->id))
        ->toThrow(PlanNotVisible::class);

    expect(titlesOf($repository->listForActor($stranger->id, PlanStatus::Active, new PageQuery(25, null))))
        ->toBe([]);
});

it('refuses a Plan whose membership role no MemberRole case recognises', function (): void {
    // Fail closed. An unrecognised role is corrupt data no writer in this codebase can produce
    // (every writer writes MemberRole::X->value), and the alternatives are a ValueError at
    // hydration for every reader of the table - which is what an enum cast on the model would
    // buy - or a 500 halfway through a page.
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();

    member($plan, $actor, MemberRole::Member, '2026-01-01 10:00:00');
    DB::table('plan_members')
        ->where('plan_id', $plan->id)
        ->where('user_id', $actor->id)
        ->update(['role' => 'wizard']);

    expect(fn () => app(PlanRepository::class)->findVisibleTo($plan->id, $actor->id))
        ->toThrow(PlanNotVisible::class);

    expect(app(PlanRepository::class)->listForActor($actor->id, PlanStatus::Active, new PageQuery(25, null))->items)
        ->toBe([]);
});

it('refuses a Plan whose membership status no MemberStatus case recognises', function (): void {
    // The status twin of 'refuses a Plan whose membership role no MemberRole case recognises'.
    // "Deny, not 500" was applied to both columns of contextFor() and, until this case existed,
    // proved for only one of them: the policy-comparison tests enumerate MemberStatus::cases(),
    // so every status they write is by construction one the enum recognises, and nothing else in
    // this file wrote one outside it.
    //
    // MemberStatus::tryFrom() is what makes the refusal: with from() in its place this throws
    // ValueError out of findVisibleTo() and the 404 becomes a 500. The list's answer comes from
    // somewhere else again - the SQL `plan_members.status = 'active'` filter excludes the row
    // before contextFor() can see it - so the from() mutation reds the first assertion only, and
    // the second is held open by the query instead. Expect the same error shape its twin
    // describes when proving that second half: dropping that SQL filter reds as an ERROR rather
    // than a `Failed asserting ...` diff, because the row then reaches contextFor(), fails to
    // resolve, and listForActor() raises its RuntimeException.
    $plan = Plan::factory()->create();
    $actor = User::factory()->hasProfile()->create();

    member($plan, $actor, MemberRole::Member, '2026-01-01 10:00:00');
    DB::table('plan_members')
        ->where('plan_id', $plan->id)
        ->where('user_id', $actor->id)
        ->update(['status' => 'wizard']);

    expect(fn () => app(PlanRepository::class)->findVisibleTo($plan->id, $actor->id))
        ->toThrow(PlanNotVisible::class);

    expect(app(PlanRepository::class)->listForActor($actor->id, PlanStatus::Active, new PageQuery(25, null))->items)
        ->toBe([]);
});

it('keeps an archived Plan visible to its active member', function (): void {
    // findVisibleTo() deliberately does NOT filter plans.status, and this is what holds that
    // open. §5.3 allows every GET on an archived Plan - "members keep reading their history" -
    // and puts PlanNotEditable (409) on the write paths, which run AFTER visibility has already
    // succeeded. Narrowing this query with where('plans.status', 'active') would therefore turn
    // every archived-Plan 409 into a 404 and make the 409 unreachable, and before this case
    // existed that change passed this file green.
    //
    // listForActor() is the method that does filter on plans.status, and "filters the list on
    // plans.status and defaults nothing itself" pins it. The two rules differ on purpose: the
    // list is a choice of which Plans to show, this method is an authorization answer.
    $actor = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->archived()->create(['title' => 'Over']);

    member($plan, $actor, MemberRole::Owner, '2026-01-01 10:00:00');

    $context = app(PlanRepository::class)->findVisibleTo($plan->id, $actor->id);

    expect($context->plan->title)->toBe('Over');
    expect($context->plan->status)->toBe(PlanStatus::Archived->value);
    expect($context->role)->toBe(MemberRole::Owner);
});

it('filters the list on plans.status and defaults nothing itself', function (): void {
    $actor = User::factory()->hasProfile()->create();
    $active = Plan::factory()->create(['title' => 'Live']);
    $archived = Plan::factory()->archived()->create(['title' => 'Over']);

    member($active, $actor, MemberRole::Member, '2026-01-01 10:00:00');
    member($archived, $actor, MemberRole::Member, '2026-01-02 10:00:00');

    $repository = app(PlanRepository::class);

    expect(titlesOf($repository->listForActor($actor->id, PlanStatus::Active, new PageQuery(25, null))))->toBe(['Live']);
    expect(titlesOf($repository->listForActor($actor->id, PlanStatus::Archived, new PageQuery(25, null))))->toBe(['Over']);
    // §7.3: cancelled is accepted by the filter and matches nothing, because nothing in Wave 2
    // writes it.
    expect(titlesOf($repository->listForActor($actor->id, PlanStatus::Cancelled, new PageQuery(25, null))))->toBe([]);
});

it('pages forward in updated_at DESC, id DESC order and then stops', function (): void {
    $actor = User::factory()->hasProfile()->create();

    foreach (range(1, 5) as $index) {
        $plan = Plan::factory()->create(['title' => "Plan {$index}"]);
        member($plan, $actor, MemberRole::Member, '2026-01-01 10:00:00');
        // Written straight to the column: Plan::factory() stamps updated_at from now(), and
        // five rows written in the same millisecond would make the order a coin toss.
        DB::table('plans')->where('id', $plan->id)->update(['updated_at' => "2026-02-0{$index} 10:00:00.000000+00"]);
    }

    $repository = app(PlanRepository::class);

    $page1 = $repository->listForActor($actor->id, PlanStatus::Active, new PageQuery(2, null));
    expect(titlesOf($page1))->toBe(['Plan 5', 'Plan 4']);
    expect($page1->nextCursor)->not->toBeNull();

    // CursorPage::$nextCursor is ?string, and Pest's ->not->toBeNull() above does not narrow
    // that for PHPStan (no phpstan-phpunit-style assert annotation is registered for it). This
    // is a real control-flow check, not a cast or a @var override: it throws if the Pest
    // assertion just above it was somehow wrong, and it is what lets fromString() below accept
    // a plain string.
    $cursor1 = $page1->nextCursor;

    if ($cursor1 === null) {
        throw new RuntimeException('page 1 of 3 must carry a next cursor.');
    }

    $page2 = $repository->listForActor(
        $actor->id,
        PlanStatus::Active,
        new PageQuery(2, PageCursor::fromString($cursor1)),
    );
    expect(titlesOf($page2))->toBe(['Plan 3', 'Plan 2']);

    $cursor2 = $page2->nextCursor;

    if ($cursor2 === null) {
        throw new RuntimeException('page 2 of 3 must carry a next cursor.');
    }

    $page3 = $repository->listForActor(
        $actor->id,
        PlanStatus::Active,
        new PageQuery(2, PageCursor::fromString($cursor2)),
    );
    expect(titlesOf($page3))->toBe(['Plan 1']);
    expect($page3->nextCursor)->toBeNull();
});

// There is no "treats a cursor it did not issue as no cursor" case here any more. An earlier
// draft of this task decoded a raw ?string $cursor itself and tolerated anything that failed to
// parse. Now that $query->cursor is a typed ?PageCursor, a value this API did not issue is
// rejected by PageCursor::fromString() - which throws - before a PageQuery exists to pass in;
// the resources section's PageCursorTest pins that rejection, and its ValidCursor rule is what
// turns it into a 422 at the HTTP boundary (see the task's Decisions).

it('never puts a Plan with a NULL updated_at on the first page only', function (): void {
    // S-37's lower-reachability half. plans.updated_at is nullable (timestampsTz(6) makes both
    // timestamps ->nullable()), Postgres sorts NULLs FIRST on DESC, and a keyset predicate over
    // a NULL evaluates to NULL - so without the whereNotNull filter this row leads page 1 and
    // then vanishes from every page after it. Consistently absent is the lesser evil, and
    // Eloquent fills the column on every write, so no row the application produces is affected.
    $actor = User::factory()->hasProfile()->create();

    foreach (['Ghost', 'Second', 'First'] as $title) {
        $plan = Plan::factory()->create(['title' => $title]);
        member($plan, $actor, MemberRole::Member, '2026-01-01 10:00:00');
    }

    $ghost = Plan::query()->where('title', 'Ghost')->firstOrFail();
    DB::table('plans')->where('id', $ghost->id)->update(['updated_at' => null]);
    DB::table('plans')->where('title', 'Second')->update(['updated_at' => '2026-02-01 10:00:00.000000+00']);
    DB::table('plans')->where('title', 'First')->update(['updated_at' => '2026-02-02 10:00:00.000000+00']);

    $page1 = app(PlanRepository::class)->listForActor($actor->id, PlanStatus::Active, new PageQuery(2, null));

    expect(titlesOf($page1))->toBe(['First', 'Second']);
    expect($page1->nextCursor)->toBeNull();
});

it('reads a page of Plans in exactly one query', function (): void {
    // §6.1: member_count "is computed with a withCount aggregate, never by loading members - an
    // N+1 over members is the obvious trap on GET /plans". Three Plans, one statement.
    $actor = User::factory()->hasProfile()->create();

    foreach (range(1, 3) as $index) {
        $plan = Plan::factory()->create();
        member($plan, $actor, MemberRole::Member, "2026-01-0{$index} 10:00:00");
    }

    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    $page = app(PlanRepository::class)->listForActor($actor->id, PlanStatus::Active, new PageQuery(25, null));

    expect($page->items)->toHaveCount(3);
    expect($queries)->toHaveCount(1);
});

it('caps the page size at PageQuery::MAX_LIMIT', function (): void {
    $actor = User::factory()->hasProfile()->create();

    foreach (range(1, 3) as $index) {
        $plan = Plan::factory()->create();
        member($plan, $actor, MemberRole::Member, "2026-01-0{$index} 10:00:00");
    }

    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    app(PlanRepository::class)->listForActor($actor->id, PlanStatus::Active, new PageQuery(5000, null));

    // The cap has to be visible in the SQL, not only in the row count: three rows come back
    // either way, so a row-count assertion here would pass with no cap at all.
    expect(implode(' ', $queries))->toContain('limit '.(PageQuery::MAX_LIMIT + 1));
});

it('clamps a limit of 0 up to one row rather than returning an empty page', function (): void {
    // pageSize()'s max(1, ...) arm, which every other case in this file misses: they pass 2, 25
    // or 5000. Amendment 7 measured what pageQuery() does with the raw input - absent yields
    // DEFAULT_LIMIT, but '' and null both yield 0 (intval(null)), 'abc' yields 0 and '-5'
    // yields -5 - so 0 and negatives are values this method can really be handed whenever the
    // limit rule one layer out is missing or marked nullable. Without the arm, min(MAX_LIMIT, 0)
    // makes this limit(1) with take(0): an empty page AND a null cursor for an actor who does
    // have Plans, which a client reads as "no Plans" rather than as a clamp. The null cursor
    // follows by construction rather than needing its own measurement - take(0) leaves
    // $page->last() null, and a cursor is built from the last row or not at all.
    $actor = User::factory()->hasProfile()->create();

    foreach (range(1, 2) as $index) {
        $plan = Plan::factory()->create(['title' => "Plan {$index}"]);
        member($plan, $actor, MemberRole::Member, '2026-01-01 10:00:00');
        // Explicit updated_at for the same reason 'pages forward in updated_at DESC, id DESC
        // order and then stops' writes one: two rows stamped in the same millisecond make
        // "which row is first" a coin toss.
        DB::table('plans')->where('id', $plan->id)->update(['updated_at' => "2026-02-0{$index} 10:00:00.000000+00"]);
    }

    $page = app(PlanRepository::class)->listForActor($actor->id, PlanStatus::Active, new PageQuery(0, null));

    expect(titlesOf($page))->toBe(['Plan 2']);
    // The cursor matters as much as the row: a page of one with no way forward is still a dead
    // end for the other Plan. This assertion has a red of its own, independent of the clamp -
    // narrowing the has-a-next-page test from $rows->count() > $size to > $size + 1 leaves the
    // row correct and the cursor null.
    expect($page->nextCursor)->not->toBeNull();
});

it('finds a Plan with no membership check, and refuses a malformed id', function (): void {
    // §10.3's preview and §10.7 step 3 both read a Plan the caller is not a member of.
    $plan = Plan::factory()->create(['title' => 'Open']);
    $repository = app(PlanRepository::class);

    expect($repository->findById($plan->id)?->title)->toBe('Open');
    expect($repository->findById('not-a-uuid'))->toBeNull();
    expect($repository->findById('019a0b7e-7c2a-7f3e-8b10-2f6c4d5e6a7b'))->toBeNull();
});

it('counts active members for a Plan the caller cannot see', function (): void {
    $plan = Plan::factory()->create();
    PlanMember::factory()->active()->create(['plan_id' => $plan->id]);
    PlanMember::factory()->create(['plan_id' => $plan->id]);
    PlanMember::factory()->removed()->create(['plan_id' => $plan->id]);

    $repository = app(PlanRepository::class);

    expect($repository->countActiveMembers($plan->id))->toBe(1);
    expect($repository->countActiveMembers('not-a-uuid'))->toBe(0);
});

/**
 * @param  CursorPage<PlanContext>  $page
 * @return list<string>
 */
function titlesOf(CursorPage $page): array
{
    return array_map(fn (PlanContext $context) => $context->plan->title, $page->items);
}
