<?php

declare(strict_types=1);

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('lets an admin leave, writing left, keeping their role and writing member.left', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $admin = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $admin, MemberRole::Admin, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($admin);

    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$admin->id}")
        ->assertNoContent();

    $membership->refresh();

    // `left`, not `removed`: MemberStatus's docblock says a membership "ends either by their
    // own choice (left) or by someone else's (removed)", and spec §9.3 treats a departing user
    // as having left every Plan rather than as having been removed from it. This distinction is
    // the only record of which of the two happened.
    expect($membership->status)->toBe(MemberStatus::Left->value, 'leaving writes left');

    // role UNCHANGED, unlike RemoveMember's demotion and unlike DeleteAccount's. Spec §8.3 says
    // so in terms ("leaves role as it is"), and the role someone held when they chose to go is
    // part of what `left` records. It is safe because every reader of plan_members.role filters
    // on status first - findVisibleTo() through grantsAccess(), §8.1's list, and
    // DeleteAccount::successorFor()'s `where status = active` before its `where role`.
    expect($membership->role)->toBe(MemberRole::Admin->value, 'leaving does not demote');

    // The row is updated, never deleted: UNIQUE(plan_id, user_id) makes it the only place a
    // re-invite can land (spec §10.7 step 7), and deleting it would erase the fact that a
    // departure happened.
    expect(PlanMember::query()->where('plan_id', $plan->id)->count())
        ->toBe(2, 'the row is updated, never deleted');

    expect(PlanActivity::query()->where('plan_id', $plan->id)->count())
        ->toBe(1, 'leaving writes exactly one activity row');

    $row = PlanActivity::query()->where('plan_id', $plan->id)->sole();

    expect($row->activity_type)->toBe(ActivityType::MemberLeft->value, 'the activity type');
    expect($row->actor_id)->toBe($admin->id, 'the actor is the person who left');
    expect($row->subject_type)->toBe('User', 'spec §11.1 maps member.left to subject_type User');
    expect($row->subject_id)->toBe($admin->id, 'the subject is the same person');
    expect($row->metadata)->toBe([], 'spec §11.1 gives member.left an empty metadata object');
});

it('refuses the owner with 403 and the one actionable body in this wave', function (): void {
    $owner = userWithPassword();
    $other = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    $membership = member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $other, MemberRole::Admin, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    // 403 and not 409: the caller IS a member and the Plan is fine; what is wrong is who they
    // are relative to the action (spec §9.2). And it is the one 403 in Wave 2 that does not use
    // Laravel's generic body, because it is the one refusal with an exit - blueprint §135's
    // "for actionable errors" rule applied to the only place in this wave that needs it. The
    // three exits the sentence points at are transfer (§9.3), archive (§7.4) or delete (§7.3),
    // and DELETE /me (whose §9.3 branch 3 archives it for them).
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$owner->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Transfer ownership or archive this Plan before leaving it.');

    expect($membership->refresh()->status)
        ->toBe(MemberStatus::Active->value, 'the owner is still an active member');
});

it('refuses the sole-member owner the same way, which is the whole last-owner rule', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    [, , $access] = signIn($owner);

    // Spec §9.2: the sole-member owner is refused by the same rule, which is the right answer
    // and not an accident. Letting them leave creates an active Plan with zero members and a
    // non-member owner, which nothing in Cycle 1 can ever clean up - and which is exactly
    // PurgeDeletedUsersCommand's loud-failure branch, an active Plan still owned by an overdue
    // soft-deleted user, which will not self-heal on its own. This refusal is the only thing
    // keeping that branch unreachable.
    //
    // The same body and the same status as the previous case, with no member count anywhere:
    // canLeave() takes one argument because plans.owner_id is a single NOT NULL column, so
    // "the last owner cannot leave" reduces to "the owner cannot leave".
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$owner->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Transfer ownership or archive this Plan before leaving it.');
});

it('lets a member leave an archived Plan', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $leaver = userWithPassword();
    $plan = Plan::factory()->create([
        'owner_id' => $owner->id,
        'status' => PlanStatus::Archived->value,
        'archived_at' => '2026-02-01 09:00:00+00',
    ]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $leaver, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($leaver);

    // Spec §5.3: leaving is always allowed, including on an archived Plan. A rule that can trap
    // a person in a Plan they want out of is the wrong rule, and there is no state worth
    // protecting by refusing. §5.3's own table lists this as the one allowed DELETE on a member
    // row.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$leaver->id}")
        ->assertNoContent();

    expect($membership->refresh()->status)->toBe(MemberStatus::Left->value, 'they left');
});

it('does not require a verified email address to leave', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $leaver = User::factory()->unverified()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $leaver, MemberRole::Member, '2026-01-02 10:00:00+00');

    $token = $leaver->createToken('api', ['*'], now()->addMinutes(15));

    // Spec §7.5's table puts "DELETE .../members/{user} when it is the actor themselves" in the
    // ungated column, for §5.3's reason. This is also the half of the gate that route middleware
    // could not express: the same URI, gated for the other branch.
    withToken($token->plainTextToken)->deleteJson("/api/v1/plans/{$plan->id}/members/{$leaver->id}")
        ->assertNoContent();

    expect($membership->refresh()->status)->toBe(MemberStatus::Left->value, 'they left');
});

it('sends an upper-cased self id down the leave branch, not the removal branch', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $leaver = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $leaver, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($leaver);

    // Str::uuid7() emits lowercase, so a client that upper-cased the id would otherwise take the
    // RemoveMember branch, where canRemoveMember(Member, Member) is false - a member refused 403
    // for leaving their own Plan. The {plan} stays lowercase deliberately: a non-canonical-case
    // {plan} is a 404 at the findVisibleTo() seam, so upper-casing it too would make this case
    // appear to prove the opposite.
    //
    // The STATUS is what distinguishes the two branches here, not the response code: `left` is
    // LeavePlan's write and `removed` is RemoveMember's, and both branches answer 204, so this
    // assertion cannot be satisfied by the wrong branch succeeding.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/".strtoupper($leaver->id))
        ->assertNoContent();

    expect($membership->refresh()->status)
        ->toBe(MemberStatus::Left->value, 'an upper-cased self id is still a departure, not a removal');
});

it('answers 404 when a non-member tries to leave, and when a member leaves twice', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $stranger = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    [, , $strangerAccess] = signIn($stranger);

    withToken($strangerAccess)->deleteJson("/api/v1/plans/{$plan->id}/members/{$stranger->id}")
        ->assertNotFound()
        ->assertJsonPath('message', 'Plan not found.');

    $leaver = userWithPassword();
    member($plan, $leaver, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $leaverAccess] = signIn($leaver);

    // MANDATORY before the actor switch, and measured here rather than assumed: Sanctum's guard
    // caches the resolved user on the guard INSTANCE, the request is swapped on each call but
    // that cache never is, and signIn() does not clear it because it POSTs to the PUBLIC login
    // route and resolves no sanctum guard at all. Without this call, the two requests below ran
    // as $stranger: strcasecmp($leaver->id, $stranger->id) is non-zero, so the departure below
    // took the RemoveMember branch instead, findVisibleTo() refused $stranger, and
    // assertNoContent() saw 404. Observed, not derived - that is the exact red this call fixed.
    //
    // One call, not two: requests 2 and 3 are both $leaver, so the cache this request populates
    // is the right one for the next.
    forgetAuthenticatedUser();

    withToken($leaverAccess)->deleteJson("/api/v1/plans/{$plan->id}/members/{$leaver->id}")
        ->assertNoContent();

    // A second DELETE is a 404 rather than another 204, and that falls out of spec §3.5 rather
    // than being a decision taken here: the row is now `left`, grantsAccess() is false for it,
    // and findVisibleTo() answers PlanNotVisible. Recorded because 204-again would also have
    // been defensible, and it is not what this implementation does.
    withToken($leaverAccess)->deleteJson("/api/v1/plans/{$plan->id}/members/{$leaver->id}")
        ->assertNotFound()
        ->assertJsonPath('message', 'Plan not found.');

    expect(PlanActivity::query()->where('plan_id', $plan->id)->count())
        ->toBe(1, 'the refused second departure wrote no second activity row');
});

it('removes a member as the owner, writing removed, demoting the role and writing member.removed', function (): void {
    $owner = userWithPassword();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $target, MemberRole::Admin, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$target->id}")
        ->assertNoContent();

    // The row count FIRST, before any refresh(). Spec §8.3: the row is updated, never deleted -
    // UNIQUE(plan_id, user_id) makes it the only place a re-invite can land (§10.7), and
    // deleting it would erase the fact that a removal happened. Asserted before the refresh
    // because Model::refresh() on a row that has been deleted underneath it calls findOrFail()
    // and throws ModelNotFoundException - so an implementation that deleted would fail on an
    // Eloquent internal rather than on the sentence above, and the next reader would have to
    // work out what the failure meant.
    expect(PlanMember::query()->where('plan_id', $plan->id)->count())
        ->toBe(2, 'the row is updated, never deleted');

    $membership->refresh();

    expect($membership->status)->toBe(MemberStatus::Removed->value, 'removal writes removed, not left');
    expect($membership->role)->toBe(MemberRole::Member->value, 'removal demotes the role to member');

    expect(PlanActivity::query()->where('plan_id', $plan->id)->count())
        ->toBe(1, 'removal writes exactly one activity row');

    $row = PlanActivity::query()->where('plan_id', $plan->id)->sole();

    expect($row->activity_type)->toBe(ActivityType::MemberRemoved->value, 'the activity type');
    expect($row->actor_id)->toBe($owner->id, 'the actor is whoever did the removing');
    expect($row->subject_id)->toBe($target->id, 'the subject is the person removed');
    expect($row->metadata)->toBe([], 'spec §11.1 gives member.removed an empty metadata object');
});

it('lets an admin remove a member but not another admin, and never the owner', function (): void {
    $admin = userWithPassword();
    $owner = User::factory()->hasProfile()->create();
    $peerAdmin = User::factory()->hasProfile()->create();
    $plainMember = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    $ownerMembership = member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $admin, MemberRole::Admin, '2026-01-02 10:00:00+00');
    $peerMembership = member($plan, $peerAdmin, MemberRole::Admin, '2026-01-03 10:00:00+00');
    $memberMembership = member($plan, $plainMember, MemberRole::Member, '2026-01-04 10:00:00+00');

    // All three requests below are the SAME actor, $admin, so no forgetAuthenticatedUser() is
    // needed between them: the guard's cached user is the one every request wants.
    [, , $access] = signIn($admin);

    // Spec §3.3: an Admin may remove a Member.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$plainMember->id}")
        ->assertNoContent();

    expect($memberMembership->refresh()->status)->toBe(MemberStatus::Removed->value, 'the member is gone');

    // Spec §3.3: only the Owner may remove an Admin. The alternative - an admin may remove any
    // non-owner - makes `manage members` indistinguishable from `remove members` and leaves the
    // owner's appointment power revocable by its beneficiaries' peers.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$peerAdmin->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    // Spec §3.3 / §9.1 from the other side: nobody removes the Owner. The BODY, not just the
    // status, is what separates this refusal from the owner's own departure a few cases up -
    // that one answers 403 with the actionable sentence, this one with Laravel's generic body.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$owner->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    expect($peerMembership->refresh()->status)->toBe(MemberStatus::Active->value, 'the peer admin stayed');
    expect($ownerMembership->refresh()->status)->toBe(MemberStatus::Active->value, 'the owner stayed');
    expect($ownerMembership->role)->toBe(MemberRole::Owner->value, 'and kept the role');
});

it('refuses a member removing anyone with 403', function (): void {
    $actor = userWithPassword();
    $owner = User::factory()->hasProfile()->create();
    $peer = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $actor, MemberRole::Member, '2026-01-02 10:00:00+00');
    $peerMembership = member($plan, $peer, MemberRole::Member, '2026-01-03 10:00:00+00');

    [, , $access] = signIn($actor);

    // blueprint §9 gives `remove members` to Owner and Admin and not to Member.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$peer->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    expect($peerMembership->refresh()->status)->toBe(MemberStatus::Active->value, 'nothing was written');
});

it('answers 409 when removing another member from an archived Plan', function (): void {
    $owner = userWithPassword();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create([
        'owner_id' => $owner->id,
        'status' => PlanStatus::Archived->value,
        'archived_at' => '2026-02-01 09:00:00+00',
    ]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    // Spec §5.3's table: "DELETE .../members/{user} for another member" is refused on an
    // archived Plan, while the same URI for the actor themselves is allowed. The pair of this
    // case and "lets a member leave an archived Plan" is the clearest statement of what §8.3
    // means by one route and two use cases, and is the only thing in the suite holding that
    // asymmetry open - a fix over-applied to the other branch reds there, not here.
    withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$target->id}")
        ->assertStatus(409)
        ->assertJsonPath('message', 'This Plan has been archived.');

    expect($membership->refresh()->status)->toBe(MemberStatus::Active->value, 'nothing was written');
});

it('answers 404 with the Plan body for a non-member actor, whatever {user} names', function (): void {
    $stranger = userWithPassword();
    $owner = User::factory()->hasProfile()->create();
    $target = User::factory()->hasProfile()->create();
    $outsider = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($stranger);

    // Spec §5.2's ordering, with four spellings, and only three of them can see a member-first
    // ordering: a {user} who really IS an active member of this Plan answers 'Plan not found.'
    // under both orderings, so a single spelling against a real member proves nothing. The
    // ordering is held open by this loop and by nothing structural - Task 8's seam-bypass gate
    // scans findById and countActiveMembers only, so findActiveMember() can be called with no
    // authorization decision in front of it.
    $spellings = [$target->id, $outsider->id, 'not-a-uuid', '0193f0e0-0000-7000-8000-000000000000'];

    foreach ($spellings as $spelling) {
        withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$spelling}")
            ->assertNotFound()
            ->assertJsonPath('message', 'Plan not found.');
    }

    expect($spellings)->toHaveCount(4, 'all four spellings must be probed, or the loop proves less than it claims');
});

it('answers 404 with the member body for a {user} who is not an active member', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    $otherOwner = User::factory()->hasProfile()->create();
    $otherPlan = Plan::factory()->create(['owner_id' => $otherOwner->id]);
    $elsewhere = User::factory()->hasProfile()->create();
    member($otherPlan, $otherOwner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($otherPlan, $elsewhere, MemberRole::Member, '2026-01-02 10:00:00+00');

    $alreadyLeft = User::factory()->hasProfile()->create();
    PlanMember::factory()->create([
        'plan_id' => $plan->id,
        'user_id' => $alreadyLeft->id,
        'role' => MemberRole::Member->value,
        'status' => MemberStatus::Left->value,
        'joined_at' => '2026-01-02 10:00:00+00',
    ]);

    [, , $access] = signIn($owner);

    // Spec §17.3's IDOR rows: a member of a DIFFERENT Plan, a row that is already `left`, and a
    // non-uuid all answer the same precise body - which is safe only because findVisibleTo() ran
    // first and this caller has already proved they can see the Plan (§5.2).
    foreach ([$elsewhere->id, $alreadyLeft->id, 'not-a-uuid'] as $spelling) {
        withToken($access)->deleteJson("/api/v1/plans/{$plan->id}/members/{$spelling}")
            ->assertNotFound()
            ->assertJsonPath('message', 'That person is not a member of this Plan.');
    }

    expect(PlanMember::query()->where('plan_id', $plan->id)->where('status', MemberStatus::Removed->value)->count())
        ->toBe(0, 'none of the three refusals wrote a removal');
});

it('refuses an unverified actor removing another member, before it looks the Plan up', function (): void {
    $unverified = User::factory()->unverified()->hasProfile()->create();
    $owner = User::factory()->hasProfile()->create();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $unverified->id]);

    member($plan, $unverified, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    $otherPlan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($otherPlan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    $token = $unverified->createToken('api', ['*'], now()->addMinutes(15));

    // Spec §7.5 gates removing another member, and THIS CASE IS THE ONLY THING IN THE SUITE
    // THAT HOLDS THAT GATE OPEN. VerifiedGateTest derives its gated/ungated partition from the
    // real route table; this route correctly carries no `verified`, because the same URI is also
    // a departure (§8.3) and middleware cannot tell the two apart - so its row there is a
    // disclosed false negative and stays green with the in-application check deleted entirely.
    //
    // The body is EnsureEmailIsVerified's own sentence, so a client cannot tell this
    // in-application gate from the middleware on PATCH .../members/{user}. Spec §5.4's table
    // claims "This action is unauthorized." for the unverified case and is wrong (baseline
    // S-27); that sentence is what a BARE AuthorizationException would have produced here, and
    // it is the body this route's ROLE refusals carry - so publishing it for the verification
    // refusal too would merge two refusals a client can act on differently.
    //
    // Asserted through assertJsonPath() and not against raw bytes: a render() closure encodes
    // compactly while the middleware's abort(403) goes through Handler::prepareJsonResponse()
    // with JSON_PRETTY_PRINT, so the two sentences are different BYTES and the same object
    // (baseline S-30). No client and no decoding assertion can see the difference.
    withToken($token->plainTextToken)->deleteJson("/api/v1/plans/{$plan->id}/members/{$target->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified.');

    expect($membership->refresh()->status)->toBe(MemberStatus::Active->value, 'nothing was written');

    // The ORDER, and this is the assertion that pins it: a Plan the actor cannot see at all. The
    // check runs before findVisibleTo(), so the answer is 403 and not 404 - the same answer
    // EnsureEmailIsVerified gives on every gated route, where it runs before the controller and
    // therefore before any repository. A version that checked verification AFTER resolving the
    // Plan would answer 404 here, and the assertion above would not notice.
    //
    // Both requests use the one token and one actor, so no forgetAuthenticatedUser() is needed.
    withToken($token->plainTextToken)->deleteJson("/api/v1/plans/{$otherPlan->id}/members/{$owner->id}")
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified.');
});

it('leaves a removed member with no further access to the Plan', function (): void {
    $owner = userWithPassword();
    $target = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $targetMembership = member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $ownerAccess] = signIn($owner);
    [, , $targetAccess] = signIn($target);

    withToken($targetAccess)->getJson("/api/v1/plans/{$plan->id}/members")->assertOk();

    // BEFORE the owner's request, not only before the re-read below, and this is the correction
    // that makes the case mean anything. Sanctum's guard caches the resolved user on the guard
    // instance and swaps only the request, so without this call the DELETE below ran as $target
    // - the request the test intends as the OWNER removing someone. strcasecmp($target->id,
    // $target->id) is zero, so it took the LeavePlan branch, wrote `left`, answered 204, and
    // assertNoContent() passed while RemoveMember was never called at all: the whole use case
    // could throw unconditionally with this case green. Both halves of the fix are needed - this
    // call, and the row assertions after it, because 204 is what BOTH branches answer.
    forgetAuthenticatedUser();

    withToken($ownerAccess)->deleteJson("/api/v1/plans/{$plan->id}/members/{$target->id}")
        ->assertNoContent();

    // The only observable that distinguishes the two branches at 204. `removed` is RemoveMember's
    // write and `left` is LeavePlan's, and the demotion is RemoveMember's alone - so these two
    // lines are what say the OWNER removed them rather than the target having left.
    $targetMembership->refresh();

    expect($targetMembership->status)
        ->toBe(MemberStatus::Removed->value, 'the owner removed them; they did not leave');
    expect($targetMembership->role)
        ->toBe(MemberRole::Member->value, 'and RemoveMember demoted the role, which LeavePlan does not');

    // Again before the actor switches back. Spec §17.3 makes forgetAuthenticatedUser() mandatory
    // in any test that makes an authenticated request and then asserts access is gone, because
    // the guard caches the resolved user: measured, "sign in, GET /me (200), delete every token
    // row, GET /me again" returns 200 from the cache with zero token rows in the database. Here
    // it is load-bearing for a second, simpler reason - the previous request resolved $owner, and
    // without this call the re-read below would run as $owner, who is still a member and would
    // get 200.
    forgetAuthenticatedUser();

    withToken($targetAccess)->getJson("/api/v1/plans/{$plan->id}/members")
        ->assertNotFound()
        ->assertJsonPath('message', 'Plan not found.');
});
