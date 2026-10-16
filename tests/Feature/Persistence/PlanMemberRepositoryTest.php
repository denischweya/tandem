<?php

declare(strict_types=1);

use App\Application\Plans\CursorPage;
use App\Application\Plans\PageQuery;
use App\Application\Plans\PlanMemberNotFound;
use App\Application\Plans\PlanMemberRepository;
use App\Application\Plans\PlanMemberView;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Shared\PageCursor;
use App\Infrastructure\Persistence\Eloquent\EloquentPlanMemberRepository;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('binds the member repository interface to its Eloquent implementation', function (): void {
    // toBeInstanceOf would assert nothing against a declared return type; app() hands back
    // mixed for an interface name, so the real force here is the container. Without the bind
    // in AppServiceProvider::register() this line raises BindingResolutionException - "Target
    // [App\Application\Plans\PlanMemberRepository] is not instantiable" - rather than
    // returning the wrong object.
    expect(app(PlanMemberRepository::class))->toBeInstanceOf(EloquentPlanMemberRepository::class);
});

it('finds an active member of the Plan', function (): void {
    // The positive control for findActiveMember(), and a one-row fixture on purpose: every
    // refusal below has to leave THIS test green, which is what makes each refusal
    // attributable to the filter it names. Its own red is a mutation that stops the lookup
    // finding a real active member - flipping activeMemberQuery()'s status value from `active`
    // to `invited` reds it - and not a filter removal, because with one row in the table every
    // filter removal still returns that row.
    $plan = Plan::factory()->create();
    $user = User::factory()->hasProfile()->create();
    member($plan, $user, MemberRole::Admin, '2026-01-01 10:00:00');

    $found = app(PlanMemberRepository::class)->findActiveMember($plan->id, $user->id);

    expect($found->user_id)->toBe($user->id);
    expect($found->role)->toBe(MemberRole::Admin->value);
});

it('refuses a user who is not an active member of this Plan', function (): void {
    // §8.2: a {user} who is not an active member of {plan} answers 404, not 403 and not 422 -
    // "a 403 or a validation error would confirm that the id names a real user".
    //
    // Three layers hold the five refusals open, and each needs its own mutation: the
    // `status = 'active'` filter refuses $invited, $left and $removed; the `plan_id` filter
    // refuses $elsewhere; the `user_id` filter refuses $stranger.
    //
    // $insider is not one of the refused ids and is not decoration - it is the row that makes
    // the `user_id` filter observable at all. With no active member of THIS Plan in the
    // fixture, deleting that filter leaves all five cases still throwing, because the status
    // filter alone refuses every remaining row, and the filter that keys the lookup to the
    // asked-for user would have no red anywhere in this file.
    $plan = Plan::factory()->create();
    $repository = app(PlanMemberRepository::class);

    $insider = User::factory()->hasProfile()->create();
    member($plan, $insider, MemberRole::Owner, '2026-01-01 10:00:00');

    $stranger = User::factory()->hasProfile()->create();
    $invited = PlanMember::factory()->create(['plan_id' => $plan->id]);
    $left = PlanMember::factory()->left()->create(['plan_id' => $plan->id]);
    $removed = PlanMember::factory()->removed()->create(['plan_id' => $plan->id]);

    // A real, active member of a DIFFERENT Plan: the IDOR case §17.3 enumerates.
    $otherPlan = Plan::factory()->create();
    $elsewhere = User::factory()->hasProfile()->create();
    member($otherPlan, $elsewhere, MemberRole::Member, '2026-01-01 10:00:00');

    foreach ([$stranger->id, $invited->user_id, $left->user_id, $removed->user_id, $elsewhere->id] as $userId) {
        expect(fn () => $repository->findActiveMember($plan->id, $userId))
            ->toThrow(PlanMemberNotFound::class);
    }
});

it('refuses a malformed user id and a malformed plan id without reaching Postgres', function (): void {
    // §5.1: "Three identifiers need the same treatment - {plan}, {user} and {invite} - each in
    // its own repository, each with its own test." Without the shape check this is
    // SQLSTATE[22P02] and a 500.
    //
    // Both halves of activeMemberQuery()'s `||` are asserted, and each is proved by deleting
    // its own disjunct: deleting the whole condition reds both at once and would show only
    // that one of the two fires.
    $plan = Plan::factory()->create();
    $user = User::factory()->hasProfile()->create();
    member($plan, $user, MemberRole::Member, '2026-01-01 10:00:00');

    $repository = app(PlanMemberRepository::class);

    expect(fn () => $repository->findActiveMember($plan->id, 'not-a-uuid'))
        ->toThrow(PlanMemberNotFound::class);
    expect(fn () => $repository->findActiveMember('not-a-uuid', $user->id))
        ->toThrow(PlanMemberNotFound::class);
});

it('returns a left or removed membership row so a re-join can reuse it', function (): void {
    // §10.7 step 7: UNIQUE(plan_id, user_id) plus permanently-retained left/removed rows means
    // anyone who previously left already HAS a row, and the join path finds and assigns it
    // rather than inserting.
    //
    // Five answers, four layers, and the plan_id one is the expensive case to get wrong: a
    // membership of ANOTHER Plan handed to that upsert would have it reassign a row belonging
    // to a different Plan. The uuid checks are asserted per disjunct for the reason the
    // malformed-id test above states.
    $plan = Plan::factory()->create();
    $otherPlan = Plan::factory()->create();
    $left = PlanMember::factory()->left()->create(['plan_id' => $plan->id]);

    $repository = app(PlanMemberRepository::class);

    expect($repository->findMembership($plan->id, $left->user_id)?->status)->toBe(MemberStatus::Left->value);
    expect($repository->findMembership($otherPlan->id, $left->user_id))->toBeNull();
    expect($repository->findMembership($plan->id, User::factory()->create()->id))->toBeNull();
    expect($repository->findMembership($plan->id, 'not-a-uuid'))->toBeNull();
    expect($repository->findMembership('not-a-uuid', $left->user_id))->toBeNull();
});

it('accepts an eligible transfer target', function (): void {
    // The positive control for findTransferTarget(): every refusal in the next test has to
    // leave this green. Its own red is the account filter inverted - whereNull('suspended_at')
    // to whereNotNull - which refuses the one target that is eligible.
    $plan = Plan::factory()->create();
    $target = User::factory()->hasProfile()->create();
    member($plan, $target, MemberRole::Admin, '2026-01-01 10:00:00');

    expect(app(PlanMemberRepository::class)->findTransferTarget($plan->id, $target->id)->user_id)
        ->toBe($target->id);
});

it('refuses every transfer target DeleteAccount::successorFor would refuse', function (): void {
    // §9.3: "The target must be eligible, and eligibility is DeleteAccount::successorFor()'s
    // filter, reused verbatim: status = active, and the target's ACCOUNT neither soft-deleted
    // nor suspended." All four conditions, not a sample - a transfer endpoint that omits one
    // hands a Plan to someone who can never administer it, which is the defect that filter
    // exists to prevent.
    //
    // Three layers again, and the third is invisible: the `status` filter refuses $invited and
    // $left, `whereNull('suspended_at')` refuses $suspended, and $deleted is refused by User's
    // SoftDeletes global scope, which whereHas() is what applies.
    //
    // Deleting the whole whereHas line loses BOTH account conditions at once - there is then no
    // `users` subquery to carry either - so that mutation proves neither half on its own.
    // Isolating them takes two: ->whereHas('user') with no closure keeps the scope and drops
    // the suspension check, and withTrashed() inside the closure keeps the suspension check and
    // drops the scope. Measured both ways, and the soft-delete half needed $deleted moved to
    // the front of this loop to observe at all, because Pest stops a test at its first failing
    // expectation and a later case is never evaluated.
    $repository = app(PlanMemberRepository::class);

    $plan = Plan::factory()->create();

    $invited = PlanMember::factory()->create(['plan_id' => $plan->id]);

    $left = PlanMember::factory()->left()->create(['plan_id' => $plan->id]);

    $suspended = User::factory()->hasProfile()->create();
    member($plan, $suspended, MemberRole::Member, '2026-01-01 10:00:00');
    $suspended->forceFill(['suspended_at' => now()])->save();

    $deleted = User::factory()->hasProfile()->create();
    member($plan, $deleted, MemberRole::Member, '2026-01-01 10:00:00');
    $deleted->delete();

    foreach ([$invited->user_id, $left->user_id, $suspended->id, $deleted->id] as $userId) {
        expect(fn () => $repository->findTransferTarget($plan->id, $userId))
            ->toThrow(PlanMemberNotFound::class);
    }
});

it('lists active members in joined_at ASC, id ASC order with their display names', function (): void {
    $plan = Plan::factory()->create();

    $first = User::factory()->hasProfile(['display_name' => 'Ada Lovelace'])->create();
    $second = User::factory()->hasProfile(['display_name' => 'Grace Hopper'])->create();

    member($plan, $second, MemberRole::Member, '2026-01-02 10:00:00');
    member($plan, $first, MemberRole::Owner, '2026-01-01 10:00:00');

    // Three rows that must not appear: §8.1's "the list contains status = active rows only".
    // Each one gets a profile row with a recognisable name, so that deleting the status filter
    // reads as a diff naming the leaked member rather than as the missing-profile exception the
    // hydration loop raises - a different layer entirely, with its own test at the end of this
    // file. $invited keeps PlanMemberFactory's default NULL joined_at, which is the shape an
    // invited row really has - but that does not make this test a second detector for the
    // whereNotNull filter: measured, the status filter excludes the row before the NULL check
    // can matter, so deleting whereNotNull leaves this test green and the NULL-joined_at case
    // below is the only thing in this file that reds for it.
    $invited = User::factory()->hasProfile(['display_name' => 'Invited'])->create();
    PlanMember::factory()->create(['plan_id' => $plan->id, 'user_id' => $invited->id]);

    $departed = User::factory()->hasProfile(['display_name' => 'Departed'])->create();
    PlanMember::factory()->left()->create(['plan_id' => $plan->id, 'user_id' => $departed->id]);

    $ejected = User::factory()->hasProfile(['display_name' => 'Ejected'])->create();
    PlanMember::factory()->removed()->create(['plan_id' => $plan->id, 'user_id' => $ejected->id]);

    $page = app(PlanMemberRepository::class)->listActiveMembers($plan->id, new PageQuery(25, null));

    expect(namesOf($page))->toBe(['Ada Lovelace', 'Grace Hopper']);

    // Four of PlanMemberView's five fields are asserted here and the fifth, displayName, is
    // what namesOf() reads. userId and status are the two that nothing else in this file would
    // notice: userId is the column this read model exists to publish and $row->id sits one
    // token away from $row->user_id in the constructor call, and status is HARDCODED to
    // MemberStatus::Active in the implementation - asserted here so that hardcoding is a
    // decision with a red (change it to Invited and this line fails) rather than an unchecked
    // constant.
    expect($page->items[0]->userId)->toBe($first->id);
    expect($page->items[0]->role)->toBe(MemberRole::Owner);
    expect($page->items[0]->status)->toBe(MemberStatus::Active);
    expect($page->items[0]->joinedAt->format('Y-m-d'))->toBe('2026-01-01');
    expect($page->nextCursor)->toBeNull();
});

it('pages the member list forward and then stops', function (): void {
    $plan = Plan::factory()->create();

    foreach (range(1, 5) as $index) {
        $user = User::factory()->hasProfile(['display_name' => "Member {$index}"])->create();
        member($plan, $user, MemberRole::Member, "2026-01-0{$index} 10:00:00");
    }

    $repository = app(PlanMemberRepository::class);

    $page1 = $repository->listActiveMembers($plan->id, new PageQuery(2, null));
    expect(namesOf($page1))->toBe(['Member 1', 'Member 2']);
    expect($page1->nextCursor)->not->toBeNull();

    // CursorPage::$nextCursor is ?string and Pest's ->not->toBeNull() above does not narrow
    // that for the analyser - it is a runtime assertion PHPStan cannot see through. This is
    // real control-flow narrowing rather than a cast or an inline override, both of which
    // would silence the analyser while leaving the test asserting less than it looks like it
    // does; the expectation above stays where it is, beside it.
    $cursor1 = $page1->nextCursor;

    if ($cursor1 === null) {
        throw new RuntimeException('page 1 of 3 must carry a next cursor.');
    }

    $page2 = $repository->listActiveMembers($plan->id, new PageQuery(2, PageCursor::fromString($cursor1)));
    expect(namesOf($page2))->toBe(['Member 3', 'Member 4']);

    $cursor2 = $page2->nextCursor;

    if ($cursor2 === null) {
        throw new RuntimeException('page 2 of 3 must carry a next cursor.');
    }

    $page3 = $repository->listActiveMembers($plan->id, new PageQuery(2, PageCursor::fromString($cursor2)));
    expect(namesOf($page3))->toBe(['Member 5']);
    expect($page3->nextCursor)->toBeNull();
});

it('breaks a joined_at tie on plan_members.id, within a page and across the page boundary', function (): void {
    // §6.3 orders this list `joined_at ASC, id ASC`, and every other fixture in this file gives
    // each member a distinct joined_at - so nothing else here exercises the `id` half: neither
    // the trailing orderBy nor the keyset's orWhere tie branch. Two members who joined in the
    // same microsecond are what need it, and plan_members.joined_at is timestamptz(6) with no
    // uniqueness of any kind. Without the tie-break the order within a tied group is whatever
    // Postgres returns, and a cursor built from the timestamp alone either repeats the tied row
    // or skips it.
    //
    // The expected order is computed from the ids rather than assumed, because HasUuids issues
    // uuid7 and the lexical order of two of them is a fact about when they were generated, not
    // something this test should pin.
    $plan = Plan::factory()->create();

    $one = User::factory()->hasProfile(['display_name' => 'Tie A'])->create();
    $two = User::factory()->hasProfile(['display_name' => 'Tie B'])->create();

    $memberOne = member($plan, $one, MemberRole::Member, '2026-01-01 10:00:00');
    $memberTwo = member($plan, $two, MemberRole::Member, '2026-01-01 10:00:00');

    $expected = $memberOne->id < $memberTwo->id ? ['Tie A', 'Tie B'] : ['Tie B', 'Tie A'];

    $repository = app(PlanMemberRepository::class);

    $page1 = $repository->listActiveMembers($plan->id, new PageQuery(1, null));

    expect(namesOf($page1))->toBe([$expected[0]]);
    expect($page1->nextCursor)->not->toBeNull();

    $cursor = $page1->nextCursor;

    if ($cursor === null) {
        throw new RuntimeException('a page of one with two matching rows must carry a next cursor.');
    }

    $page2 = $repository->listActiveMembers($plan->id, new PageQuery(1, PageCursor::fromString($cursor)));

    expect(namesOf($page2))->toBe([$expected[1]]);
});

it('excludes an active member with a NULL joined_at from a page large enough to reach it', function (): void {
    // S-37, the blocking half, and this fixture is a different shape from its sibling in
    // PlanRepositoryTest on purpose. plan_members.joined_at is timestamptz(6) NULL, in no index
    // and under no CHECK, and it is this cursor's leading key: a keyset predicate of the form
    // (joined_at, id) > (?, ?) evaluates to NULL for such a row, so once a cursor has been
    // issued the row can never be returned again - the API showing a member once and then
    // losing them from the middle of a list.
    //
    // Postgres sorts NULLs LAST on ASC (DeleteAccount::successorFor()'s docblock records the
    // same fact), so the NULL row sits at the END of this list rather than leading it. That is
    // what makes the page SIZE load-bearing here: with a page of 2 over three real members the
    // NULL row falls outside page 1's LIMIT and is then excluded from page 2 by the NULL-valued
    // keyset predicate, so both pages look right, nothing reads the row, and the filter's
    // absence is undetectable. One real member, one NULL row and a page of 25 is a page big
    // enough to reach it.
    //
    // Expect the shape of the red, so a re-runner does not read it as a broken step: with
    // whereNotNull deleted this fails as an uncaught RuntimeException from the hydration loop,
    // before either expectation below is evaluated, and the message it carries - "has no
    // joined_at, which this query excludes" - is the invariant S-37 exists to protect. The
    // nextCursor expectation is NOT what detects that mutation: two rows is short of a page of
    // 25 either way, so it is null in both worlds. It guards the separate property that a short
    // page issues no cursor.
    $plan = Plan::factory()->create();

    $user = User::factory()->hasProfile(['display_name' => 'Member 1'])->create();
    member($plan, $user, MemberRole::Member, '2026-01-01 10:00:00');

    $ghostUser = User::factory()->hasProfile(['display_name' => 'Ghost'])->create();
    PlanMember::factory()->create([
        'plan_id' => $plan->id,
        'user_id' => $ghostUser->id,
        'status' => MemberStatus::Active->value,
        'joined_at' => null,
    ]);

    $page = app(PlanMemberRepository::class)->listActiveMembers($plan->id, new PageQuery(25, null));

    expect(namesOf($page))->toBe(['Member 1']);
    expect($page->nextCursor)->toBeNull();
});

it('reads a page of members in exactly one query', function (): void {
    // §8.1: "Each row needs display_name from user_profiles. One eager load, not an N+1."
    //
    // The row count is asserted first so the query count cannot pass by matching nothing: a
    // method that returned an empty page would also issue one statement.
    $plan = Plan::factory()->create();

    foreach (range(1, 4) as $index) {
        $user = User::factory()->hasProfile()->create();
        member($plan, $user, MemberRole::Member, "2026-01-0{$index} 10:00:00");
    }

    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    $page = app(PlanMemberRepository::class)->listActiveMembers($plan->id, new PageQuery(25, null));

    expect($page->items)->toHaveCount(4);
    expect($queries)->toHaveCount(1);
});

it('caps the member page size at PageQuery::MAX_LIMIT', function (): void {
    // The cap has to be visible in the SQL and not only in the row count: one row comes back
    // either way, so a row-count assertion here would pass with no cap at all.
    $plan = Plan::factory()->create();
    $user = User::factory()->hasProfile()->create();
    member($plan, $user, MemberRole::Member, '2026-01-01 10:00:00');

    $queries = [];
    DB::listen(function (QueryExecuted $event) use (&$queries): void {
        $queries[] = $event->sql;
    });

    app(PlanMemberRepository::class)->listActiveMembers($plan->id, new PageQuery(5000, null));

    expect(implode(' ', $queries))->toContain('limit '.(PageQuery::MAX_LIMIT + 1));
});

it('clamps a limit of 0 up to one row rather than returning an empty page', function (): void {
    // pageSize()'s max(1, ...) arm, which every other case in this file misses: they pass 1, 2,
    // 25 or 5000. A limit of 0 is a value this method can really be handed - the global
    // ConvertEmptyStringsToNull middleware turns `?limit=` into a present null, and intval(null)
    // is 0 - so without the arm min(MAX_LIMIT, 0) makes this limit(1) with take(0): an empty
    // page AND a null cursor for a Plan that does have members, which a client reads as "no
    // members" rather than as a clamp.
    $plan = Plan::factory()->create();

    foreach (range(1, 2) as $index) {
        $user = User::factory()->hasProfile(['display_name' => "Member {$index}"])->create();
        member($plan, $user, MemberRole::Member, "2026-01-0{$index} 10:00:00");
    }

    $page = app(PlanMemberRepository::class)->listActiveMembers($plan->id, new PageQuery(0, null));

    expect(namesOf($page))->toBe(['Member 1']);
    // The cursor matters as much as the row: a page of one with no way forward is still a dead
    // end for the other member. It has a red of its own, independent of the clamp - narrowing
    // the has-a-next-page test from $rows->count() > $size to > $size + 1 leaves the row right
    // and the cursor null.
    expect($page->nextCursor)->not->toBeNull();
});

it('refuses to publish a member whose profile row is missing', function (): void {
    // The force that makes this unreachable through the application is registration: it is the
    // only path that creates a user, and it writes the user_profiles row. The constraints do
    // NOT make it unreachable - user_profiles.user_id being UNIQUE NOT NULL gives at most one
    // profile per user and never at least one, and display_name being varchar(80) NOT NULL says
    // nothing about whether a row exists. User::factory()->create() with no ->hasProfile() is
    // exactly the persisted user with no profile row, which is what this fixture uses and what
    // nothing else can produce.
    //
    // Loud, because the alternative is publishing an empty display_name for a real member.
    $plan = Plan::factory()->create();
    $user = User::factory()->create();
    member($plan, $user, MemberRole::Member, '2026-01-01 10:00:00');

    expect(fn () => app(PlanMemberRepository::class)->listActiveMembers($plan->id, new PageQuery(25, null)))
        ->toThrow(RuntimeException::class, 'has no user_profiles row');
});

/**
 * @param  CursorPage<PlanMemberView>  $page
 * @return list<string>
 */
function namesOf(CursorPage $page): array
{
    return array_map(fn (PlanMemberView $view) => $view->displayName, $page->items);
}
