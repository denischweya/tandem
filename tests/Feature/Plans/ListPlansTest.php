<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('returns only the Plans the actor is an active member of, newest change first', function (): void {
    $actor = userWithPassword();
    $someoneElse = User::factory()->hasProfile()->create();

    $mine = ownedPlan($actor);
    $joined = ownedPlan($someoneElse);
    member($joined, $actor, MemberRole::Member, '2026-02-01T00:00:00+00:00');
    $theirs = ownedPlan($someoneElse);

    // Raw updates, so the order is fixed by the test rather than by how fast the three creates
    // above happened to run. DB::table() and not forceFill(): Eloquent's updateTimestamps()
    // would be one more thing to reason about in a test about ordering.
    DB::table('plans')->where('id', $mine->id)->update(['updated_at' => '2026-03-01 10:00:00.000000+00']);
    DB::table('plans')->where('id', $joined->id)->update(['updated_at' => '2026-03-02 10:00:00.000000+00']);
    DB::table('plans')->where('id', $theirs->id)->update(['updated_at' => '2026-03-03 10:00:00.000000+00']);

    [, , $access] = signIn($actor);

    // No 403 and no 404 anywhere on this route: the result set IS the authorization, which is
    // why $theirs is simply absent rather than refused.
    withToken($access)->getJson('/api/v1/plans')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $joined->id)
        ->assertJsonPath('data.0.viewer_role', MemberRole::Member->value)
        ->assertJsonPath('data.1.id', $mine->id)
        ->assertJsonPath('data.1.viewer_role', MemberRole::Owner->value)
        ->assertJsonPath('meta.next_cursor', null);
});

it('drops a Plan the actor left, was removed from, or has only been invited to', function (): void {
    $actor = userWithPassword();
    $owner = User::factory()->hasProfile()->create();

    foreach ([MemberStatus::Left, MemberStatus::Removed, MemberStatus::Invited] as $status) {
        $plan = ownedPlan($owner);

        PlanMember::factory()->create([
            'plan_id' => $plan->id,
            'user_id' => $actor->id,
            'role' => MemberRole::Member->value,
            'status' => $status->value,
            'joined_at' => $status === MemberStatus::Invited ? null : '2026-01-05T00:00:00+00:00',
        ]);
    }

    [, , $access] = signIn($actor);

    // The active-only filter is the thing to get right: UNIQUE(plan_id, user_id) means a
    // departure is a status change and never a row deletion, so without the filter a user who
    // left would keep seeing the Plan in their list for ever.
    withToken($access)->getJson('/api/v1/plans')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.next_cursor', null);

    expect(PlanMember::query()->where('user_id', $actor->id)->count())
        ->toBe(3, 'the rows are still there - the filter, not their absence, is what hides them');
});

it('defaults to active, returns archived only when asked, and matches nothing for cancelled', function (): void {
    $actor = userWithPassword();

    $active = ownedPlan($actor);
    $archived = ownedPlan($actor);
    DB::table('plans')->where('id', $archived->id)->update([
        'status' => PlanStatus::Archived->value,
        'archived_at' => '2026-05-01 10:00:00.000000+00',
    ]);

    [, , $access] = signIn($actor);

    // The default matters: spec §9.3 branch 3 archives a Plan behind the departing owner's
    // back, so a member's list would silently gain an archived Plan through nobody's action -
    // and blueprint §134's "Nothing planned yet" empty state would be wrongly suppressed by it.
    withToken($access)->getJson('/api/v1/plans')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $active->id);

    withToken($access)->getJson('/api/v1/plans?status=archived')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $archived->id);

    // Accepted and matches nothing: nothing in Wave 2 writes cancelled, and accepting it now
    // means the filter does not change when a later cycle makes it reachable.
    withToken($access)->getJson('/api/v1/plans?status=cancelled')
        ->assertOk()
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('meta.next_cursor', null);
});

it('pages forward with limit and cursor, repeating and skipping nothing', function (): void {
    $actor = userWithPassword();

    $oldest = ownedPlan($actor);
    $middle = ownedPlan($actor);
    $newest = ownedPlan($actor);

    DB::table('plans')->where('id', $oldest->id)->update(['updated_at' => '2026-03-01 10:00:00.000000+00']);
    DB::table('plans')->where('id', $middle->id)->update(['updated_at' => '2026-03-02 10:00:00.000000+00']);
    DB::table('plans')->where('id', $newest->id)->update(['updated_at' => '2026-03-03 10:00:00.000000+00']);

    [, , $access] = signIn($actor);

    $first = withToken($access)->getJson('/api/v1/plans?limit=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $newest->id)
        ->assertJsonPath('data.1.id', $middle->id);

    $cursor = $first->json('meta.next_cursor');

    expect($cursor)->toBeString('a full page must carry a cursor, or a client cannot ask for the next one');

    $second = withToken($access)->getJson('/api/v1/plans?limit=2&cursor='.urlencode((string) $cursor))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $oldest->id)
        ->assertJsonPath('meta.next_cursor', null);

    // Spelled out rather than implied by the two counts above: the property a paginator has to
    // have is that the pages partition the result set, and three rows arriving as 2 + 1 is
    // also what a paginator that repeated the second row would produce if it dropped the third.
    $seen = array_merge(
        array_column((array) $first->json('data'), 'id'),
        array_column((array) $second->json('data'), 'id'),
    );

    expect($seen)->toBe([$newest->id, $middle->id, $oldest->id], 'the two pages must partition the list, in order');
});

it('refuses a cursor this API did not issue, as a malformed cursor', function (): void {
    $actor = userWithPassword();
    ownedPlan($actor);

    [, , $access] = signIn($actor);

    // A 422, not a silent first page: ValidCursor runs during validation, before this route's
    // own code ever sees the value, so a cursor that is mistyped, stale-cached, or simply made
    // up is refused rather than quietly answered with a correct-looking page the client cannot
    // tell apart from the one it actually asked for.
    withToken($access)->getJson('/api/v1/plans?cursor='.urlencode('!!!not-a-cursor!!!'))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('cursor');
});

// The brief this test file is built from bundled a limit-below-one, a limit-above-the-cap, a
// non-numeric limit and an unknown status into one it() with a foreach over the first three.
// Split here instead, one case per rule clause: Pest aborts an it() at its first failing
// assertion, so a single test (looped or not) that deletes, say, min:1 would still show the
// '0' case red and say nothing about whether max:100 or the integer check still work - the
// earliest failure is the only one a shared test can ever report. Five independent it() blocks
// each stand or fall on their own, so a mutation that breaks exactly one rule reds exactly one
// of them.

it('refuses a limit of zero, below the minimum, with a 422 naming limit', function (): void {
    $actor = userWithPassword();
    ownedPlan($actor);

    [, , $access] = signIn($actor);

    // This is the first place in the wave that drives min:1 through rules() over a real HTTP
    // request for this FormRequest: the sibling test that exercises "a limit of zero clamps"
    // builds a PageQuery value object directly and never reaches rules() at all, so a deleted
    // min:1 would leave that test green while a real `?limit=0` request sailed through
    // unclamped to the repository.
    withToken($access)->getJson('/api/v1/plans?limit=0')
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('limit');
});

it('refuses a limit of 101, above the cap, with a 422 naming limit', function (): void {
    $actor = userWithPassword();
    ownedPlan($actor);

    [, , $access] = signIn($actor);

    withToken($access)->getJson('/api/v1/plans?limit=101')
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('limit');
});

it('accepts a limit of 100, the cap itself, rather than refusing it off-by-one', function (): void {
    $actor = userWithPassword();
    ownedPlan($actor);

    [, , $access] = signIn($actor);

    // 100 is the cap, so it must be accepted - an off-by-one in the max rule is the likeliest
    // defect here and it is invisible from the 101 case alone.
    withToken($access)->getJson('/api/v1/plans?limit=100')->assertOk();
});

it('refuses a non-numeric limit with a 422 naming limit', function (): void {
    $actor = userWithPassword();
    ownedPlan($actor);

    [, , $access] = signIn($actor);

    withToken($access)->getJson('/api/v1/plans?limit=twenty')
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('limit');
});

it('refuses an unknown status with a 422 naming status', function (): void {
    $actor = userWithPassword();
    ownedPlan($actor);

    [, , $access] = signIn($actor);

    withToken($access)->getJson('/api/v1/plans?status=nope')
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('status');
});

// Three cases below close the route-level half of the paging surface a FormRequest-level test
// cannot reach on its own: what a bare, present-and-null or over-cap query string actually does
// through the real HTTP kernel, with the global TrimStrings/ConvertEmptyStringsToNull
// middleware in front of validation rather than bypassed by a hand-built request.

it('defaults limit to 25 when none is given, even when more Plans than that exist', function (): void {
    $actor = userWithPassword();

    foreach (range(1, 26) as $ignored) {
        ownedPlan($actor);
    }

    [, , $access] = signIn($actor);

    // An absent limit must fall to the value object's own default (25) rather than to
    // whatever a bare GET happens to return - with only 25 or fewer Plans in play, a route
    // that silently returned everything would pass a plain assertOk() just as easily. 26
    // Plans make 25 the only page size consistent with the default, and the second assertion
    // below confirms a 26th Plan is still reachable through a next page rather than dropped.
    withToken($access)->getJson('/api/v1/plans')
        ->assertOk()
        ->assertJsonCount(25, 'data');

    expect(withToken($access)->getJson('/api/v1/plans')->json('meta.next_cursor'))
        ->toBeString('a 26th Plan must still be reachable through a second page, not silently dropped');
});

it('refuses a present-but-null limit with a 422, not a silent default', function (): void {
    $actor = userWithPassword();
    ownedPlan($actor);

    [, , $access] = signIn($actor);

    // The global ConvertEmptyStringsToNull middleware turns `?limit=` into a present null, not
    // an absent key, and limit's rule carries `integer` with no `nullable` - so this must 422
    // rather than silently taking the default or clamping a null to anything.
    withToken($access)->getJson('/api/v1/plans?limit=')
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('limit');
});

it('refuses a present-but-null cursor with a 422, the same shape as a present-but-null limit', function (): void {
    $actor = userWithPassword();
    ownedPlan($actor);

    [, , $access] = signIn($actor);

    // Measured, not assumed either way: Illuminate\Validation\Validator::
    // presentOrRuleIsImplicit() falls through to validatePresent() - Arr::has(), true for a
    // present null - so cursor's non-implicit `string` rule DOES run against the present null
    // `?cursor=` arrives as. An absent cursor means first page (ReadsPageQuery's own
    // behaviour); a PRESENT null one 422s, exactly like a present-but-null limit does, rather
    // than being treated as equivalent to absent.
    withToken($access)->getJson('/api/v1/plans?cursor=')
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('cursor');
});

it('returns an empty data array and a null cursor for a user with no Plans', function (): void {
    $actor = userWithPassword();
    [, , $access] = signIn($actor);

    // What blueprint §134's "Nothing planned yet" renders against, and the one case a client
    // must not be able to confuse with an error.
    withToken($access)->getJson('/api/v1/plans')
        ->assertOk()
        ->assertExactJson(['data' => [], 'meta' => ['next_cursor' => null]]);
});

it('requires authentication', function (): void {
    getJson('/api/v1/plans')->assertUnauthorized();
});
