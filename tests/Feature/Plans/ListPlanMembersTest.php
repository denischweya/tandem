<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('lists the active members of a Plan the actor belongs to, longest-standing first', function (): void {
    // Users created in the REVERSE of the order the list must return, deliberately, and
    // BEFORE the Plan or any plan_members row: ids are UUIDv7 and therefore time-sortable, so
    // this also puts plan_members.user_id in strictly descending order against the joined_at
    // order the assertions below require. Measured rather than assumed to be unnecessary:
    // with plan_members.plan_id = ? as the only equality predicate, Postgres chooses an Index
    // Scan on plan_members_plan_id_user_id_unique(plan_id, user_id) - EXPLAIN confirms it -
    // which returns rows in user_id order with NO ORDER BY at all. A fixture whose user_id
    // order already agreed with joined_at order (creating $owner, then $second, then $third,
    // in that order, as an earlier version of this fixture did) would stay green with both of
    // `listActiveMembers()`'s ->orderBy() calls deleted, because the index scan's own order
    // would satisfy the assertions by coincidence - the exact "sorted-by-accident pass" this
    // comment warns against, just from a different accidental source than row-insertion order.
    $third = User::factory()->hasProfile(['display_name' => 'Ada Lovelace'])->create();
    $second = User::factory()->hasProfile(['display_name' => 'Grace Hopper'])->create();
    $owner = userWithPassword();

    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    // plan_members row insertion, ALSO scrambled, and independently of the user-id ordering
    // above: this opposes a sort-by-accident from plan_members' own heap/TID order (or from a
    // planner choice keyed on plan_members.id) the same way the paragraph above opposes one
    // keyed on user_id. Only a real `ORDER BY joined_at ASC, id ASC` can satisfy every
    // assertion below against both scrambles at once.
    member($plan, $third, MemberRole::Member, '2026-01-03 10:00:00.000003+00');
    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00.000001+00');
    member($plan, $second, MemberRole::Admin, '2026-01-02 10:00:00.000002+00');

    [, , $access] = signIn($owner);

    $response = withToken($access)->getJson("/api/v1/plans/{$plan->id}/members")
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.user_id', $owner->id)
        ->assertJsonPath('data.1.user_id', $second->id)
        ->assertJsonPath('data.1.display_name', 'Grace Hopper')
        ->assertJsonPath('data.1.role', MemberRole::Admin->value)
        ->assertJsonPath('data.1.status', MemberStatus::Active->value)
        ->assertJsonPath('data.2.user_id', $third->id)
        ->assertJsonPath('data.2.display_name', 'Ada Lovelace');

    // The published timestamp is DATE_RFC3339_EXTENDED, the format every other timestamp this
    // API returns uses (SessionResource). Asserted by shape rather than by a literal because
    // Postgres returns the microseconds it stored and the resource prints milliseconds.
    expect($response->json('data.1.joined_at'))
        ->toMatch('/^2026-01-02T10:00:00\.\d{3}\+00:00$/', 'joined_at must be DATE_RFC3339_EXTENDED');

    // The WHOLE meta object, not ->assertJsonPath('meta.next_cursor', null): data_get()
    // returns null for an absent key, so that spelling is satisfied by the envelope being
    // missing entirely - which is exactly the vacuous shape §0.3 warns about, and failOnRisky
    // is off so nothing else would say so. Asserting the object catches both the missing key
    // and the missing envelope.
    expect($response->json('meta'))
        ->toBe(['next_cursor' => null], 'a single-page list must still publish the meta envelope');
});

it('omits invited, left and removed rows, and says how many it expected', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    // The full five-key array rather than a factory state, so this test stands on its own:
    // PlanMemberFactory's definition() is role=member, status=invited, joined_at=null.
    foreach ([MemberStatus::Invited, MemberStatus::Left, MemberStatus::Removed] as $status) {
        PlanMember::factory()->create([
            'plan_id' => $plan->id,
            'user_id' => User::factory()->hasProfile()->create()->id,
            'role' => MemberRole::Member->value,
            'status' => $status->value,
            'joined_at' => $status === MemberStatus::Invited ? null : '2026-01-04 10:00:00+00',
        ]);
    }

    [, , $access] = signIn($owner);

    // The count is the assertion. §8.1 publishes active rows only, because "a list that showed
    // them would present departed people as current and make blueprint §104's 4 members
    // ambiguous", and it would tell every member that a specific person was removed.
    withToken($access)->getJson("/api/v1/plans/{$plan->id}/members")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.user_id', $owner->id);
});

it('includes a suspended member and excludes a soft-deleted one', function (): void {
    $owner = userWithPassword();
    $suspended = User::factory()->hasProfile(['display_name' => 'Suspended Person'])
        ->create(['suspended_at' => now()]);
    $deleted = User::factory()->hasProfile(['display_name' => 'Deleted Person'])->create();

    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $suspended, MemberRole::Member, '2026-01-02 10:00:00+00');
    $deletedMembership = member($plan, $deleted, MemberRole::Member, '2026-01-03 10:00:00+00');

    // §9.3 is what makes a soft-deleted member absent: deleting an account sets every one of
    // that user's memberships to left with role demoted to member. Done here through the same
    // two columns rather than by calling DeleteAccount, so this test asserts §8.1 and not §9.3.
    $deletedMembership->forceFill([
        'status' => MemberStatus::Left->value,
        'role' => MemberRole::Member->value,
    ])->save();
    $deleted->delete();

    [, , $access] = signIn($owner);

    // §8.1: "A suspended member DOES appear: suspension is an account state, not a Plan state,
    // and hiding them would silently change a Plan's membership from the other members' point
    // of view. They cannot act - see §9.5." Residual, not fixed by this test: the repository
    // read filters only plan_members.status, so a suspended member counts and appears here -
    // latent per plan-corrections.md G8b, not live today because nothing in app/ writes
    // suspended_at outside this test's direct column set.
    withToken($access)->getJson("/api/v1/plans/{$plan->id}/members")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.1.display_name', 'Suspended Person');
});

it('answers 404 with the one Plan body for a signed-in non-member', function (): void {
    $stranger = userWithPassword();
    $owner = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    [, , $access] = signIn($stranger);

    // §5.1: one exception, PlanNotVisible, for a malformed id, an unknown Plan, an actor with
    // no plan_members row, and an actor whose row does not grantsAccess(). Not 403 - "a 403
    // confirms the resource exists, which leaks Plan existence to non-members" (spec §8.1).
    withToken($access)->getJson("/api/v1/plans/{$plan->id}/members")
        ->assertNotFound()
        ->assertJsonPath('message', 'Plan not found.');
});

it('answers 404 with the same body for an invited member who never accepted', function (): void {
    $invited = userWithPassword();
    $owner = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    PlanMember::factory()->create([
        'plan_id' => $plan->id,
        'user_id' => $invited->id,
        'role' => MemberRole::Member->value,
        'status' => MemberStatus::Invited->value,
        'joined_at' => null,
    ]);

    [, , $access] = signIn($invited);

    // §3.5: only `active` grants any capability - invited, left and removed grant nothing at
    // all, not even view Plan.
    withToken($access)->getJson("/api/v1/plans/{$plan->id}/members")
        ->assertNotFound()
        ->assertJsonPath('message', 'Plan not found.');
});

it('answers 404 rather than 500 for a {plan} that is not a uuid', function (): void {
    $user = userWithPassword();

    [, , $access] = signIn($user);

    // Baseline §8 item 1 / C-1: without a shape guard, `where('id', 'not-a-uuid')` against a
    // uuid column is Postgres 22P02, QueryException is absent from
    // Handler::prepareException()'s table, and the route answers 500 {"message":"Server
    // Error"}. §5.1 rejects Route::whereUuid('plan') for this because the router's own 404
    // body is different AND pretty-printed, so one route would answer two 404s.
    withToken($access)->getJson('/api/v1/plans/not-a-uuid/members')
        ->assertNotFound()
        ->assertJsonPath('message', 'Plan not found.');
});

it('does not require a verified email address', function (): void {
    $unverified = User::factory()->unverified()->hasProfile(['display_name' => 'Unverified Person'])->create();
    $plan = Plan::factory()->create(['owner_id' => $unverified->id]);

    member($plan, $unverified, MemberRole::Owner, '2026-01-01 10:00:00+00');

    $token = $unverified->createToken('api', ['*'], now()->addMinutes(15));

    // §7.5: "verified gates every Plan route that acts on other members' behalf or creates
    // state other members see. It does not gate reading, joining, or leaving." If reading were
    // gated, a newly-joined unverified user would be 403'd on the Plan they had just joined.
    withToken($token->plainTextToken)->getJson("/api/v1/plans/{$plan->id}/members")
        ->assertOk()
        ->assertJsonPath('data.0.display_name', 'Unverified Person');
});

it('requires authentication', function (): void {
    $plan = Plan::factory()->create();

    getJson("/api/v1/plans/{$plan->id}/members")->assertUnauthorized();
});

it('pages with the cursor and loses no row between the pages', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $second = User::factory()->hasProfile()->create();
    $third = User::factory()->hasProfile()->create();
    member($plan, $second, MemberRole::Member, '2026-01-02 10:00:00+00');
    member($plan, $third, MemberRole::Member, '2026-01-03 10:00:00+00');

    [, , $access] = signIn($owner);

    $firstPage = withToken($access)->getJson("/api/v1/plans/{$plan->id}/members?limit=2")
        ->assertOk()
        ->assertJsonCount(2, 'data');

    $cursor = $firstPage->json('meta.next_cursor');

    expect($cursor)->toBeString('page 1 of 3 rows at limit=2 must carry a next_cursor');

    $secondPage = withToken($access)->getJson("/api/v1/plans/{$plan->id}/members?limit=2&cursor=".urlencode((string) $cursor))
        ->assertOk()
        ->assertJsonCount(1, 'data');

    expect($secondPage->json('meta'))
        ->toBe(['next_cursor' => null], 'the last page must publish meta.next_cursor as null');

    // Union and uniqueness together, because the failure this guards against is a row that
    // appears on page 1 and then silently disappears (baseline S-37) - which a per-page count
    // alone would not see.
    $seen = array_merge($firstPage->json('data.*.user_id'), $secondPage->json('data.*.user_id'));

    expect($seen)->toHaveCount(3, 'the two pages together must return every active member once');
    expect(array_unique($seen))->toHaveCount(3, 'no member may appear on two pages');
});

it('refuses a limit above the §6.2 cap with 422', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    [, , $access] = signIn($owner);

    // §6.2 caps a page at 100. A 422 rather than a silent clamp: `max:100` publishes
    // `maximum: 100`, and clamping would publish a maximum the server ignores.
    withToken($access)->getJson("/api/v1/plans/{$plan->id}/members?limit=101")
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('limit');
});

it('refuses a malformed cursor with 422', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    [, , $access] = signIn($owner);

    // R7: adopting the resources section's ValidCursor rule means a cursor this list never
    // issued is a 422, not the repository's old silent page 1.
    withToken($access)->getJson("/api/v1/plans/{$plan->id}/members?cursor=!!!")
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('cursor');
});
