<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

// WHAT THIS FILE PROVES, AND WHERE IT IS SILENT. A file named for an invariant reads as the
// place that invariant is guaranteed, so the false negatives are named here rather than left to
// be discovered: spec §9.1 is asserted PER-TRANSITION, over the transitions these tests
// actually drive - the deliberate ownership transfer through PATCH .../members/{user}, a
// removal and a departure through DELETE .../members/{user}, and DeleteAccount's automatic
// transfer and its archive branch through DELETE /me. This is not an audit of every
// plan_members.role write path in the codebase, and real §9.1-adjacent defects are invisible to
// it. Each is named below with the mutation that measures it and the test that does catch it:
//
//   - CreatePlan's owner-row write is NOT exercised here. Nothing below calls POST /plans; every
//     Plan is seeded through the factory and the member() helper. Measured: writing the
//     creator's own membership as `admin` instead of `owner` leaves this whole file green and
//     reds CreatePlanTest's "writes the Plan, the owner active membership and one plan.created
//     row" - that, and nothing else in the suite. Do not widen this file to cover it; that test
//     is where it is covered.
//
//   - PlanPermissions::canRemoveMember's "nobody removes the Owner" rule is NOT exercised here
//     and cannot be, whatever the mutation: the only removal below is the new owner removing the
//     demoted outgoing one, so nothing here ever names an Owner as a removal target. Measured:
//     letting both the Owner and the Admin arm permit an Owner target leaves this file green and
//     reds PlanPermissionsTest's "matches blueprint section 3 point 3" matrix case and
//     LeaveAndRemoveMemberTest's "lets an admin remove a member but not another admin, and
//     never the owner" - those, and nothing else.
//
// AcceptInvite is the one Wave 2 write path that could break this invariant and that no test
// guards, here or anywhere: it assigns plan_members.role from plan_invites.role, a string column
// under no CHECK and with no enum cast, on a route a non-member can reach. It is not in the tree
// yet, so nothing here can drive it - whichever change ships it owes its own
// one-active-owner-row assertion.
//
// What this file does hold alone: the assertions below count rows that are role = owner AND
// status = active, which nothing else in the suite does. So a defect in a path this file DOES
// drive surfaces here under the name of the broken PROPERTY, and - for the transfer and the
// removal - in the member-route tests too under the name of the missing WRITE.

it('holds the one-owner invariant across a transfer, a removal and a departure', function (): void {
    // A local closure rather than a file-scope function: Pest loads each test file into the
    // global namespace, so a file-scope `function` is process-global and the second file that
    // wants the name gets a fatal "Cannot redeclare", not a test failure. tests/Pest.php is the
    // only legal home for a shared helper. A closure assigned to a variable is scoped to this
    // it() block and collides with nothing.
    $assertInvariant = function (string $when, int $expectedActivePlans): void {
        $activePlans = Plan::query()->where('status', PlanStatus::Active->value)->get();

        // THE COUNT FIRST, and it is not decoration. The assertions below live inside a
        // foreach: over an empty collection the loop body never runs, every assertion inside it
        // is skipped, and the test passes having checked nothing about ownership at all.
        // phpunit.xml sets no failOnRisky - `grep -niE "failOn|risky|beStrict" phpunit.xml`
        // finds nothing - so PHPUnit would mark such a test risky and the suite would still
        // exit 0. This line is the only thing standing between this test and that outcome.
        expect($activePlans)->toHaveCount(
            $expectedActivePlans,
            "{$when}: the invariant must be checked against {$expectedActivePlans} active Plans",
        );

        foreach ($activePlans as $activePlan) {
            $ownerRows = PlanMember::query()
                ->where('plan_id', $activePlan->id)
                ->where('role', MemberRole::Owner->value)
                ->where('status', MemberStatus::Active->value)
                ->get();

            // Exactly one, not "at least one": UNIQUE(plan_id, user_id) is the only composite
            // unique key on plan_members, so two rows for one Plan both claiming role = owner
            // are schema-legal. That is the state DeleteAccount's own comment describes - "a
            // transferred Plan carries two rows claiming role = 'owner' - the departing one,
            // now `left`, and the successor promoted just above" - and the state its demotion
            // closed.
            //
            // role is compared as the raw string column here, never through MemberRole::from()
            // or ::tryFrom(): plan_members.role is a plain string under no CHECK and with no
            // Eloquent cast, so a value no enum case recognises simply fails to match 'owner'.
            // This assertion therefore leans on neither the fail-closed tryFrom() that
            // RemoveMember and ChangeMemberRole apply to that column nor the deliberately loud
            // from() that the member list applies, and a corrupt role can only ever make this
            // count smaller, never larger.
            expect($ownerRows)->toHaveCount(
                1,
                "{$when}: active Plan {$activePlan->id} must have exactly one active owner row",
            );

            // And the two sources of truth must agree. plans.owner_id is NOT NULL with
            // RESTRICT; plan_members.role is under no constraint of any kind, so nothing in the
            // database prevents them disagreeing and only this assertion does (spec §8.1).
            expect($ownerRows->sole()->user_id)->toBe(
                $activePlan->owner_id,
                "{$when}: active Plan {$activePlan->id}'s owner row must be the user plans.owner_id names",
            );
        }
    };

    $founder = User::factory()->hasProfile()->create();
    $successor = User::factory()->hasProfile()->create();
    $bystander = User::factory()->hasProfile()->create();

    $plan = Plan::factory()->create(['owner_id' => $founder->id]);
    $founderMembership = member($plan, $founder, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $successor, MemberRole::Admin, '2026-01-02 10:00:00+00');

    // An ADMIN, not a plain member, and the role is load-bearing rather than incidental. The
    // departure below is the transition that is supposed to show §8.3's asymmetry - LeavePlan
    // writes `left` and deliberately leaves `role` alone, where RemoveMember demotes - and a
    // row seeded as `member` reads `member` under both branches, so it cannot tell them apart.
    // Seeded as an admin, the surviving `admin` on a `left` row IS the asymmetry. It is the
    // row's `status` and not its role that makes the actor switch before that request
    // observable - measured by deleting that forgetAuthenticatedUser() call, which reds on
    // `removed` against `left` - so the two assertions carry different halves of the claim.
    $bystanderMembership = member($plan, $bystander, MemberRole::Admin, '2026-01-03 10:00:00+00');

    // A second, untouched Plan with a different owner, so the count assertion above is a real
    // number and the loop is proved to iterate more than once - a scoping bug that leaked rows
    // from one Plan into another's owner count would otherwise be invisible.
    $otherFounder = User::factory()->hasProfile()->create();
    $otherPlan = Plan::factory()->create(['owner_id' => $otherFounder->id]);
    member($otherPlan, $otherFounder, MemberRole::Owner, '2026-01-01 10:00:00+00');

    // createToken() rather than signIn(): these actors need a bearer token and nothing else -
    // no refresh token, no device row, no password round trip. UserFactory's definition()
    // already sets email_verified_at, so each of them is verified, which PATCH
    // .../members/{user} requires through the `verified` middleware and DELETE
    // .../members/{user} requires inside RemoveMember itself.
    $founderToken = $founder->createToken('api', ['*'], now()->addMinutes(15))->plainTextToken;
    $successorToken = $successor->createToken('api', ['*'], now()->addMinutes(15))->plainTextToken;
    $bystanderToken = $bystander->createToken('api', ['*'], now()->addMinutes(15))->plainTextToken;

    $assertInvariant('before anything happens', 2);

    // Transition 1: spec §9.3's deliberate transfer, through PATCH with role: owner.
    withToken($founderToken)->patchJson("/api/v1/plans/{$plan->id}/members/{$successor->id}", [
        'role' => MemberRole::Owner->value,
    ])->assertOk();

    expect($plan->refresh()->owner_id)->toBe($successor->id, 'the transfer moved plans.owner_id');

    $assertInvariant('after a deliberate ownership transfer', 2);

    // BEFORE the next request, and without it this whole test is a description rather than a
    // guard. Illuminate\Auth\GuardHelpers caches the resolved user on the guard instance;
    // Sanctum's guard is refreshed with the new request on every test HTTP call but never has
    // that cached user cleared, and the guards are singletons on the one application the test
    // runs against. So a second bearer token in the same it() authenticates as the FIRST
    // token's user. Measured here before this call was added: the request below ran as the
    // founder, who is both actor and target, so it took the LeavePlan branch instead of
    // RemoveMember - 204 either way, one active owner row either way, and the transition whose
    // entire purpose is to exercise RemoveMember's demotion never happened.
    forgetAuthenticatedUser();

    // Transition 2: the new owner removes the outgoing one, who is now an admin. This is the
    // second path that can produce a stale role, and §8.3's demotion is what closes it.
    withToken($successorToken)->deleteJson("/api/v1/plans/{$plan->id}/members/{$founder->id}")
        ->assertNoContent();

    // The branch assertion, and it is not optional. DELETE .../members/{user} is one route and
    // two use cases, split on whether {user} is the actor, and BOTH answer 204 - so
    // assertNoContent() cannot say which of the two ran. The row is the only observable that
    // can: RemoveMember writes `removed` and demotes, LeavePlan writes `left` and does not.
    // Without this pair a green here proves neither branch, which is the shape of a red that
    // could come from two causes, inverted.
    $founderMembership->refresh();

    expect($founderMembership->status)
        ->toBe(MemberStatus::Removed->value, 'transition 2 must be RemoveMember, not LeavePlan');
    expect($founderMembership->role)
        ->toBe(MemberRole::Member->value, 'and RemoveMember demotes, which LeavePlan does not');

    $assertInvariant('after the new owner removed the outgoing one', 2);

    // Again before the actor switches, for the same cached-guard reason. Measured before this
    // call was added: the request below ran as the founder, whose row is `left` by now, so
    // grantsAccess() refused it and findVisibleTo() answered 404 against assertNoContent() -
    // the test died here, before the last invariant check and before every trailing count.
    forgetAuthenticatedUser();

    // Transition 3: an ordinary member leaves of their own accord. LeavePlan does NOT demote
    // `role`, deliberately (§8.3), so this transition is the one that would expose the
    // asymmetry if `role` were meaningful on a non-active row. The invariant is scoped to
    // status = active, which is exactly why it is not.
    withToken($bystanderToken)->deleteJson("/api/v1/plans/{$plan->id}/members/{$bystander->id}")
        ->assertNoContent();

    // The same branch assertion from the other side, and the half that makes §9.4's row 4
    // observable rather than argued: the departing admin's row keeps `admin` on a `left` row,
    // where the removal above demoted. Both halves of the asymmetry, observed in one test
    // rather than argued across two.
    $bystanderMembership->refresh();

    expect($bystanderMembership->status)
        ->toBe(MemberStatus::Left->value, 'transition 3 must be LeavePlan, not RemoveMember');
    expect($bystanderMembership->role)
        ->toBe(MemberRole::Admin->value, 'and LeavePlan leaves role as it is, which RemoveMember does not');

    $assertInvariant('after a member left', 2);

    // And every transition really did happen, so no invariant check above was asserting the
    // same unchanged state over and over.
    expect(PlanMember::query()->where('plan_id', $plan->id)->where('status', MemberStatus::Active->value)->count())
        ->toBe(1, 'one of three members remains active: the new owner');
    expect(PlanMember::query()->where('plan_id', $plan->id)->where('status', MemberStatus::Removed->value)->count())
        ->toBe(1, 'the outgoing owner was removed');
    expect(PlanMember::query()->where('plan_id', $plan->id)->where('status', MemberStatus::Left->value)->count())
        ->toBe(1, 'the bystander left');

    // The second Plan was never addressed by any request above, and nothing else here would
    // notice if a transition had reached across to it: the invariant holds for a Plan whose
    // owner row was rewritten correctly as well as for one nobody touched.
    expect($otherPlan->refresh()->owner_id)->toBe($otherFounder->id, 'the untouched Plan kept its owner');
});

it('scopes the invariant to active Plans, because spec §9.3 branch 3 produces one with no owner row', function (): void {
    $soleMember = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $soleMember->id]);
    $membership = member($plan, $soleMember, MemberRole::Owner, '2026-01-01 10:00:00+00');

    // An untouched Plan alongside it, so the scoped loop below has something to iterate and the
    // count assertion is a real number rather than zero.
    $otherFounder = User::factory()->hasProfile()->create();
    $otherPlan = Plan::factory()->create(['owner_id' => $otherFounder->id]);
    member($otherPlan, $otherFounder, MemberRole::Owner, '2026-01-01 10:00:00+00');

    $token = $soleMember->createToken('api', ['*'], now()->addMinutes(15))->plainTextToken;

    // The real endpoint, not DeleteAccount::execute() directly: §9.3 is already implemented and
    // already covered at the use-case level, and what this case is about is that Wave 2's
    // invariant agrees with what that endpoint really produces. DELETE /me is deliberately not
    // behind `verified` - the route file records why - so no verification step is needed here.
    //
    // No actor switch anywhere in this case, so no forgetAuthenticatedUser() is needed: the
    // cached guard only misleads a test that changes actor.
    withToken($token)->deleteJson('/api/v1/me')->assertNoContent();

    $plan->refresh();
    $membership->refresh();

    // §9.3 step 2's last resort: archived, not deleted, and both fields written - because
    // DeleteAccount writes both and a reader that checked only one would disagree with the
    // other path (§7.4).
    expect($plan->status)->toBe(PlanStatus::Archived->value, 'the sole-member Plan is archived');
    expect($plan->archived_at)->not->toBeNull('archived_at is written alongside status');

    // The departing user's row: left, with role demoted to member. This is the write that
    // produces the exception the invariant is scoped around.
    expect($membership->status)->toBe(MemberStatus::Left->value, 'the departing member is left');
    expect($membership->role)->toBe(MemberRole::Member->value, 'and demoted');

    // plans.owner_id still names them, and they are now soft-deleted. This is exactly the row
    // PurgeDeletedUsersCommand selects on at day 30, and the reason plans.owner_id's RESTRICT
    // is never asked to fire at soft-delete time.
    expect($plan->owner_id)->toBe($soleMember->id, 'plans.owner_id still names the departed owner');
    expect(User::withTrashed()->whereKey($soleMember->id)->sole()->deleted_at)
        ->not->toBeNull('and that user is soft-deleted');

    // THE EXCEPTION, asserted explicitly rather than left as the absence of a failure: this
    // Plan has no active owner row at all. Stating the invariant without the status = active
    // scope would make it false for the one state spec §9.3 deliberately produces. It is also
    // why §5.3's read-only rule is structural rather than defensive - an archived Plan can have
    // no authorized administrator, so the write paths have to refuse on the Plan's state and
    // not on the caller's identity.
    expect(PlanMember::query()
        ->where('plan_id', $plan->id)
        ->where('role', MemberRole::Owner->value)
        ->where('status', MemberStatus::Active->value)
        ->count())
        ->toBe(0, 'an archived §9.3 branch 3 Plan has no active owner row, and that is the point');

    // And the scoped invariant still holds over everything it does cover.
    $activePlans = Plan::query()->where('status', PlanStatus::Active->value)->get();

    expect($activePlans)->toHaveCount(1, 'the archived Plan is outside the scope; the other one is inside it');

    foreach ($activePlans as $activePlan) {
        expect(PlanMember::query()
            ->where('plan_id', $activePlan->id)
            ->where('role', MemberRole::Owner->value)
            ->where('status', MemberStatus::Active->value)
            ->count())
            ->toBe(1, "active Plan {$activePlan->id} must have exactly one active owner row");
    }

    expect($activePlans->first()?->id)->toBe($otherPlan->id, 'and it is the untouched Plan, not the archived one');
});

it('agrees with spec §9.3 automatic transfer: the promoted successor is the one the member list shows', function (): void {
    $founder = User::factory()->hasProfile(['display_name' => 'Ada Lovelace'])->create();
    $admin = User::factory()->hasProfile(['display_name' => 'Grace Hopper'])->create();
    $plainMember = User::factory()->hasProfile(['display_name' => 'Katherine Johnson'])->create();

    $plan = Plan::factory()->create(['owner_id' => $founder->id]);
    $founderMembership = member($plan, $founder, MemberRole::Owner, '2026-01-01 10:00:00+00');

    // The PLAIN MEMBER joins FIRST and the admin second, and that order is the whole guard.
    // resolvePlanOwnership() tries successorFor(..., Admin) and only then successorFor(...,
    // null), and both order joined_at ascending - so with the admin as the earlier joiner the
    // admin wins under BOTH attempts, and the assertion below would be green even with the
    // admin-first attempt deleted. It would prove ordering and call it admin-preference.
    // Reversed, only the admin-first attempt can produce the outcome asserted below.
    //
    // Both joined_at values are non-null, deliberately: successorFor()'s own docblock records
    // that Postgres sorts NULLs last on ASC, so a null here would be testing NULL ordering
    // instead of the preference.
    member($plan, $plainMember, MemberRole::Member, '2026-01-02 10:00:00+00');
    $adminMembership = member($plan, $admin, MemberRole::Admin, '2026-01-03 10:00:00+00');

    $founderToken = $founder->createToken('api', ['*'], now()->addMinutes(15))->plainTextToken;
    $adminToken = $admin->createToken('api', ['*'], now()->addMinutes(15))->plainTextToken;

    withToken($founderToken)->deleteJson('/api/v1/me')->assertNoContent();

    $plan->refresh();

    // resolvePlanOwnership()'s admin-successor branch. An admin first, and only then anyone
    // else, because - in that method's own words - "without the first attempt a plain member
    // who happens to have joined earlier beats every admin, which hands the Plan to someone the
    // departing owner never promoted." The fixture above is built so that this assertion can
    // only pass if that first attempt runs.
    expect($plan->owner_id)->toBe($admin->id, 'the longest-standing admin is the successor, not the earlier member');
    expect($plan->status)->toBe(PlanStatus::Active->value, 'a Plan with a successor is not archived');
    expect($adminMembership->refresh()->role)->toBe(MemberRole::Owner->value, 'the successor row is promoted too');
    expect($founderMembership->refresh()->status)->toBe(MemberStatus::Left->value, 'the departing owner is left');
    expect($founderMembership->role)->toBe(MemberRole::Member->value, 'and demoted');

    // Before switching to the admin's token, and this one is fatal without the call rather than
    // merely weak: the founder resolved by the DELETE above is cached on the guard, so the GET
    // below would authenticate as them - a soft-deleted user whose membership row is now
    // `left`. findVisibleTo() refuses that row, so the request answered 404 against assertOk()
    // when this call was absent. Measured before adding it.
    forgetAuthenticatedUser();

    // This is the clause §8.1 exists to satisfy: ownership is answered from plans.owner_id, and
    // the member list is where that answer is visible - so the agreement is asserted through
    // the endpoint rather than against the columns alone. The departed owner must be absent
    // (their row is `left`), and §9.3's demotion is what keeps the published `role` honest.
    //
    // The list is ordered joined_at ascending, so the earlier-joining plain member comes first
    // and the promoted admin second. The published role is where this file does depend on a
    // policy for plan_members.role: listActiveMembers() converts with MemberRole::from(),
    // which is loud by a recorded human ruling rather than fail-closed.
    withToken($adminToken)->getJson("/api/v1/plans/{$plan->id}/members")
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.user_id', $plainMember->id)
        ->assertJsonPath('data.0.display_name', 'Katherine Johnson')
        ->assertJsonPath('data.0.role', MemberRole::Member->value)
        ->assertJsonPath('data.1.user_id', $admin->id)
        ->assertJsonPath('data.1.display_name', 'Grace Hopper')
        ->assertJsonPath('data.1.role', MemberRole::Owner->value);
});
