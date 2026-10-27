<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanStatus;
use App\Models\PlanInvite;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

// Every user who signs in carries the password signIn() posts - either from
// tests/Pest.php::userWithPassword(), or, where a display_name has to be a known string, from
// User::factory()->hasProfile([...])->create(['password_hash' => Hash::make(...)]). UserFactory
// seeds Hash::make('password'), so signIn() on a plain factory user fails inside the helper with
// a 401 and the subject under test is never reached.

it('lists every invite of the Plan newest first, with seven fields and no token', function (): void {
    $owner = User::factory()->hasProfile(['display_name' => 'Denis Chweya'])
        ->create(['password_hash' => Hash::make('correct horse battery staple')]);
    $plan = ownedPlan($owner);

    [$oldest] = invited($plan, $owner, MemberRole::Member, 'ada@example.test');
    [$middle] = invited($plan, $owner, MemberRole::Admin);
    [$newest] = invited($plan, $owner, MemberRole::Member);

    // created_at stamped explicitly, driven at the column: three rows written inside one test can
    // land in the same microsecond, and the order under test is created_at DESC. Nothing in the
    // API writes created_at, and it is not mass-assignable on this model.
    //
    // This fixture opposes insertion order on the LEADING sort key: $oldest, $middle and $newest
    // are created (and therefore physically inserted) in that order - oldest first - but their
    // forced created_at values ascend in the SAME order, so the asserted newest-first result is
    // the reverse of both insertion order and of a bare ascending created_at read. Dropping
    // orderByDesc('plan_invites.created_at') entirely would return oldest-first here, which fails
    // the assertion below - see the companion case for the id tiebreak this fixture does NOT
    // exercise, because no two of these three rows ever share a created_at for it to break.
    DB::table('plan_invites')->where('id', $oldest->id)->update(['created_at' => '2026-10-01 09:00:00+00']);
    DB::table('plan_invites')->where('id', $middle->id)->update(['created_at' => '2026-10-02 09:00:00+00']);
    DB::table('plan_invites')->where('id', $newest->id)->update(['created_at' => '2026-10-03 09:00:00+00']);

    // An invite on ANOTHER Plan the same owner holds. Without it, a test with one Plan cannot
    // tell a query scoped to {plan} from one that lists every invite in the database.
    $otherPlan = ownedPlan($owner);
    [$foreign] = invited($otherPlan, $owner, MemberRole::Member);

    [, , $access] = signIn($owner);

    $response = withToken($access)->getJson("/api/v1/plans/{$plan->id}/invites")->assertOk();

    // The ids AND their order in one assertion: three rows, this Plan's only, newest first. A
    // foreign row appearing here changes the array, and so does a lost ordering clause.
    expect(array_column((array) $response->json('data'), 'id'))
        ->toBe([$newest->id, $middle->id, $oldest->id]);

    // The key set, exactly. A leaked invite list is not a leaked set of invites, so a `token` key
    // or a `token_hash` key must fail HERE - assertJsonStructure would pass for a superset.
    expect(array_keys((array) $response->json('data.0')))
        ->toEqualCanonicalizing([
            'id', 'email', 'role', 'expires_at', 'accepted_at', 'revoked_at', 'invited_by_name',
        ]);

    $response->assertJsonPath('data.2.email', 'ada@example.test')
        ->assertJsonPath('data.2.role', MemberRole::Member->value)
        ->assertJsonPath('data.1.role', MemberRole::Admin->value)
        ->assertJsonPath('data.0.email', null)
        ->assertJsonPath('data.0.accepted_at', null)
        ->assertJsonPath('data.0.revoked_at', null)
        ->assertJsonPath('data.0.invited_by_name', 'Denis Chweya');

    // expires_at is published as DATE_RFC3339_EXTENDED, the shape SessionResource already
    // publishes and the one the contract test asserts `format: date-time` for.
    expect($response->json('data.0.expires_at'))
        ->toMatch('/^[0-9]{4}-[0-9]{2}-[0-9]{2}T[0-9]{2}:[0-9]{2}:[0-9]{2}\.[0-9]{3}\+00:00$/');

    // And no hash anywhere in the body, not merely absent from row 0's key set. Three hashes,
    // because a resource that leaked only the first row's would still be a leak.
    $body = (string) $response->getContent();

    foreach ([$newest->id, $middle->id, $oldest->id] as $id) {
        expect($body)->not->toContain(PlanInvite::query()->whereKey($id)->sole()->token_hash);
    }

    expect($body)->not->toContain($foreign->id);
});

it('breaks a created_at tie by id, not by insertion order', function (): void {
    // The companion to the case above, per the standing rule that a comment certifying an order
    // as proven must have a case that actually reaches the secondary key: the first case's three
    // rows never share a created_at, so it proves the LEADING key (created_at DESC) and says
    // nothing about the tiebreak (id DESC). Residual 15 is why this is the production-likely
    // case rather than a corner one: every timestampTz(6) column here receives whole seconds, so
    // two invites issued inside the same second - the common case, not the rare one - share an
    // identical stored created_at.
    //
    // plan_invites carries only a single-column index('plan_id') and a unique('token_hash') -
    // no (plan_id, x) composite, unlike plan_members's (plan_id, user_id) unique index - so
    // there is no second column's incidental order to scramble against here. The only free order
    // a dropped ORDER BY could produce under `where plan_id = ?` is the table's own physical/TID
    // order, which for two rows inserted in sequence is, in practice, insertion order: the row
    // created first comes back first. $tiedFirst is created (and therefore inserted) before
    // $tiedSecond, so insertion order would return [$tiedFirst, $tiedSecond] - and because
    // PlanInvite ids are UUIDv7 and therefore time-sortable, $tiedSecond's id is the larger one,
    // so `id DESC` requires the OPPOSITE order, [$tiedSecond, $tiedFirst]. The two orderings are
    // already in direct opposition with no scrambling needed, because (unlike plan_members's
    // user_id, which belongs to a different row created independently) an invite's own id is
    // intrinsically tied to its own insertion order - there is no second identity to decouple it
    // from.
    $owner = userWithPassword();
    $plan = ownedPlan($owner);

    [$tiedFirst] = invited($plan, $owner, MemberRole::Member);
    [$tiedSecond] = invited($plan, $owner, MemberRole::Admin);

    DB::table('plan_invites')->whereIn('id', [$tiedFirst->id, $tiedSecond->id])
        ->update(['created_at' => '2026-10-05 12:00:00+00']);

    // Derived from the rows' own ids, not assumed from call order: UUIDv7 monotonicity is
    // documented for this project's models rather than guaranteed by anything this test controls.
    [$expectedFirstId, $expectedSecondId] = $tiedFirst->id < $tiedSecond->id
        ? [$tiedSecond->id, $tiedFirst->id]
        : [$tiedFirst->id, $tiedSecond->id];

    [, , $access] = signIn($owner);

    withToken($access)->getJson("/api/v1/plans/{$plan->id}/invites")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $expectedFirstId)
        ->assertJsonPath('data.1.id', $expectedSecondId);
});

it('lists an invite whose inviter no longer exists, with a null invited_by_name', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);

    // An admin issues the invite and is then HARD-deleted. plan_invites.invited_by is nullable
    // with SET NULL (the migration's own nullOnDelete), which the day-30 purge fires, and §14.1
    // clause 1 revokes only UNACCEPTED invites at soft-delete time - so an accepted invite
    // outlives its inviter and stays in this list. plan_members and user_profiles CASCADE on the
    // user row, so this single delete is the whole fixture.
    $inviter = User::factory()->hasProfile()->create();
    member($plan, $inviter, MemberRole::Admin, '2026-01-02T00:00:00+00:00');

    [$invite] = invited($plan, $inviter, MemberRole::Member);

    DB::table('users')->where('id', $inviter->id)->delete();

    [, , $access] = signIn($owner);

    withToken($access)->getJson("/api/v1/plans/{$plan->id}/invites")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $invite->id)
        ->assertJsonPath('data.0.invited_by_name', null);

    // The FK really did null the column, so the test is about a reachable row rather than about
    // a name lookup that happened to miss.
    expect(DB::table('plan_invites')->where('id', $invite->id)->whereNull('invited_by')->count())
        ->toBe(1);
});

it('refuses a plain member with 403 and a non-member with the Plan-not-found 404', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    invited($plan, $owner, MemberRole::Member, 'ada@example.test');

    $plainMember = userWithPassword();
    member($plan, $plainMember, MemberRole::Member, '2026-01-02T00:00:00+00:00');
    $stranger = userWithPassword();

    [, , $memberAccess] = signIn($plainMember);

    // 403 and not 404: this member can plainly see the Plan. The list is owner-and-admin-only
    // because it carries addresses the inviter typed, which are personal data about people
    // who are not members.
    withToken($memberAccess)->getJson("/api/v1/plans/{$plan->id}/invites")
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    forgetAuthenticatedUser();

    [, , $strangerAccess] = signIn($stranger);

    // 404 and not 403, because a 403 confirms the Plan exists and that is what leaks to a
    // non-member.
    withToken($strangerAccess)->getJson("/api/v1/plans/{$plan->id}/invites")
        ->assertNotFound()
        ->assertJsonPath('message', 'Plan not found.');
});

it("lists an archived Plan's invites, because the archived refusal is about writes", function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [$invite] = invited($plan, $owner, MemberRole::Member);

    DB::table('plans')->where('id', $plan->id)
        ->update(['status' => PlanStatus::Archived->value, 'archived_at' => now()]);

    [, , $access] = signIn($owner);

    // §5.3. DELETE answers 409 on this same Plan - RevokeInviteTest pins that - and a reader who
    // could not see the list could not see what had been revoked.
    withToken($access)->getJson("/api/v1/plans/{$plan->id}/invites")
        ->assertOk()
        ->assertJsonPath('data.0.id', $invite->id);
});

it('refuses an unverified owner with the verified gate', function (): void {
    $owner = userWithPassword(attributes: ['email_verified_at' => null]);
    $plan = ownedPlan($owner);
    invited($plan, $owner, MemberRole::Member);

    [, , $access] = signIn($owner);

    // This route is gated on `verified`: listing invites reads addresses the inviter typed. The
    // body is EnsureEmailIsVerified's own, not AuthorizationException's default - and the 403 is
    // conditional on the Accept header: getJson() sets Accept: application/json, the one value
    // for which the middleware aborts 403 at all. With Accept: */*, no Accept, or text/html the
    // same request is a 500, because the middleware falls through to
    // Redirect::guest(URL::route('verification.notice')) and routes/web.php is deliberately
    // empty. That is a known, measured gap in the gate, not this route's problem to fix, and it is
    // recorded here so nobody "fixes" this assertion by loosening it.
    withToken($access)->getJson("/api/v1/plans/{$plan->id}/invites")
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified.');
});

it('requires authentication', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    invited($plan, $owner, MemberRole::Member, 'ada@example.test');

    $response = getJson("/api/v1/plans/{$plan->id}/invites")->assertUnauthorized();

    // The addresses are the reason this matters more than a status code: an unauthenticated 200
    // here would publish them.
    expect((string) $response->getContent())->not->toContain('ada@example.test');
});
