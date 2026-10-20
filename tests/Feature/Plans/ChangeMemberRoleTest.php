<?php

declare(strict_types=1);

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Http\Requests\Api\V1\Plans\ChangeMemberRoleRequest;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

use function Pest\Laravel\travel;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('promotes a member to admin as the owner, and writes one role-changed activity row', function (): void {
    $owner = userWithPassword();
    $target = User::factory()->hasProfile(['display_name' => 'Grace Hopper'])->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [
        'role' => MemberRole::Admin->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.user_id', $target->id)
        ->assertJsonPath('data.display_name', 'Grace Hopper')
        ->assertJsonPath('data.role', MemberRole::Admin->value)
        ->assertJsonPath('data.status', MemberStatus::Active->value);

    expect($membership->refresh()->role)
        ->toBe(MemberRole::Admin->value, 'the member row must carry the new role');

    expect(PlanActivity::query()->where('plan_id', $plan->id)->count())
        ->toBe(1, 'a real role change writes exactly one activity row');

    // sole() rather than first(): it returns a non-nullable model, so reading a property off
    // it needs no null check that PHPStan level 8 would otherwise reject, and it throws if the
    // count is anything but one.
    $row = PlanActivity::query()->where('plan_id', $plan->id)->sole();

    expect($row->activity_type)->toBe(ActivityType::MemberRoleChanged->value, 'the activity type');
    expect($row->actor_id)->toBe($owner->id, 'the actor is whoever made the change');
    expect($row->subject_type)->toBe('User', 'spec §11.1 maps member.role_changed to subject_type User');
    expect($row->subject_id)->toBe($target->id, 'the subject is the member whose role changed');
    // Field names and values, never an address and never a field value a later cycle could
    // make private: spec §11.1 pins {"from":"member","to":"admin"} for this row exactly, and
    // RecordActivity::shapeOf() refuses any other key set with an InvalidArgumentException -
    // which is a 500, not a mis-filed row.
    //
    // toEqual() and NOT toBe(), measured: plan_activity.metadata is `jsonb`, which stores an
    // object's keys in its own normalised order (shortest first, then bytewise), so the row
    // reads back as {"to": ..., "from": ...} and an identity comparison on an associative array
    // fails on key ORDER - an assertion about Postgres's storage format rather than about the
    // metadata. toEqual() compares with ==, which for arrays requires the same key/value pairs
    // and ignores their order, so it keeps the from/to ASSOCIATION (a reversed transition still
    // reds) and the "and nothing else" (an extra key still reds). toEqualCanonicalizing() would
    // be the wrong relaxation: it sorts the values and would accept the transition backwards.
    expect($row->metadata)->toEqual(
        ['from' => MemberRole::Member->value, 'to' => MemberRole::Admin->value],
        'metadata is the from/to pair and nothing else',
    );
});

it('demotes an admin to member as the owner', function (): void {
    $owner = userWithPassword();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $target, MemberRole::Admin, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [
        'role' => MemberRole::Member->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.role', MemberRole::Member->value);

    expect($membership->refresh()->role)->toBe(MemberRole::Member->value, 'demoted');
});

it('accepts a change that is already true with 200, no write and no activity row', function (): void {
    $owner = userWithPassword();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    $before = $membership->refresh()->updated_at->format('Y-m-d H:i:s.u');

    [, , $access] = signIn($owner);

    // travel(1)->second() is what makes the updated_at assertion at the end of this test
    // capable of failing at all, and it is not a convenience. Model::save() is
    // `$this->isDirty() ? $this->performUpdate($query) : true`, and HasAttributes compares a
    // date-cast attribute by running BOTH sides through getDateFormat(), which is
    // 'Y-m-d H:i:s' - no $dateFormat override exists anywhere in app/. So a re-stamp inside
    // one wall-clock second is not dirty, issues no UPDATE, and leaves updated_at where it
    // was: an assertion written without this line passes whether the short-circuit below
    // exists or not. Moving Carbon::setTestNow() forward is the right instrument because
    // Eloquent's freshTimestamp() is what reads it; a column written from App\Domain\Shared\
    // Clock needs a rebound FrozenClock instead, which is what ArchivePlanTest does.
    travel(1)->second();

    // Spec §8.2 refusal 4, and §7.4's idempotence reasoning: a second identical call is
    // another success. The activity feed is immutable (blueprint §51) and must not accumulate
    // duplicates because a client retried, so the activity row is written on the transition
    // only.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [
        'role' => MemberRole::Member->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.role', MemberRole::Member->value);

    expect(PlanActivity::query()->where('plan_id', $plan->id)->count())
        ->toBe(0, 'a no-op role change writes no activity row');

    // updated_at, because "no write" has to mean no write and not merely no visible change: a
    // forceFill()->save() with an unchanged attribute sends no UPDATE, but anything that
    // touched the row through a different path would still move the timestamp - and with the
    // clock advanced above, it would move observably.
    expect($membership->refresh()->updated_at->format('Y-m-d H:i:s.u'))
        ->toBe($before, 'a no-op role change must not touch updated_at');
});

it('refuses an admin actor with 403 and the generic body', function (): void {
    $admin = userWithPassword();
    $owner = User::factory()->hasProfile()->create();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $admin, MemberRole::Admin, '2026-01-02 10:00:00+00');
    member($plan, $target, MemberRole::Member, '2026-01-03 10:00:00+00');

    [, , $access] = signIn($admin);

    // Spec §3.2: `assign admins` appears under Owner and nowhere under Admin, so an admin
    // promoting a peer is a privilege escalation the matrix forbids by omission. 403 and not
    // 404 because §5.2 leaves nothing to disclose - the admin can plainly see the Plan.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [
        'role' => MemberRole::Admin->value,
    ])
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');
});

it('refuses a member actor with 403, even for a change that is already true', function (): void {
    $actor = userWithPassword();
    $owner = User::factory()->hasProfile()->create();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $actor, MemberRole::Member, '2026-01-02 10:00:00+00');
    member($plan, $target, MemberRole::Member, '2026-01-03 10:00:00+00');

    [, , $access] = signIn($actor);

    // The ordering assertion, not just the status: idempotence is the use case's short-circuit
    // and NOT an authorization answer, so it must run after canChangeRole() and not before. A
    // version that short-circuited first would answer 200 here. One of exactly two cases in
    // this file that observe that ordering - "refuses the owner as the target with 403,
    // whatever role is asked for" is the other, on its third iteration.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [
        'role' => MemberRole::Member->value,
    ])
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');
});

it('refuses the owner as the target with 403, whatever role is asked for', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    [, , $access] = signIn($owner);

    // Spec §8.2 refusal 2: the owner's own role is never edited directly. Demoting them would
    // leave plans.owner_id pointing at a non-owner, and promoting them is a no-op. The owner's
    // role changes only as the mirror of a transfer (§9.3), which names someone ELSE as {user}.
    //
    // Three iterations, and the THIRD is the one that does work no other case in this file
    // does: with $to === Owner the requested role equals the target's current role, so a use
    // case that short-circuited on idempotence before authorizing would answer 200 here. It is
    // the stronger of the two cases that observe that ordering, because a no-op on the owner's
    // own row is also the shape that would let an owner self-demote. Pest aborts at the first
    // failing assertion, so the iterations are cheap but not independent: a red on iteration 1
    // says nothing about 2 or 3.
    foreach ([MemberRole::Member, MemberRole::Admin, MemberRole::Owner] as $role) {
        $response = withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$owner->id}", [
            'role' => $role->value,
        ]);

        // expect()->toBe() rather than assertForbidden()/assertJsonPath(): both carry no
        // failure message, and these two lines are shared by all three iterations - a red
        // would otherwise read only "Expected ... [403] but received ..." with the iteration
        // unnamed, which is exactly the information the third iteration exists to supply.
        expect($response->status())->toBe(403, "asking for role {$role->value} on the owner's own row must be refused");
        expect($response->json('message'))->toBe(
            'This action is unauthorized.',
            "asking for role {$role->value} on the owner's own row must carry the generic role-refusal body",
        );
    }

    expect(PlanMember::query()->where('plan_id', $plan->id)->where('role', MemberRole::Owner->value)->count())
        ->toBe(1, 'three refused requests must leave the single owner row exactly as it was');
});

it('answers 404 with the Plan body for a non-member, whatever {user} names', function (): void {
    $stranger = userWithPassword();
    $owner = User::factory()->hasProfile()->create();
    $target = User::factory()->hasProfile()->create();
    $outsider = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($stranger);

    // Spec §5.2's ordering, and SEVERAL spellings of {user} rather than one, because not all of
    // them can see the defect. A non-member must receive the Plan body and never the precise
    // member body, whatever the second parameter names.
    //
    // Why one value is not enough, worked through against the two possible orderings: for a
    // {user} who is a real active member of THIS Plan, a use case that resolved the member first
    // would find them and go on to throw PlanNotVisible anyway - the body is 'Plan not found.'
    // under both orderings, so that probe proves nothing about the order and is kept only as the
    // control. The others are where a member-first ordering answers "That person is not a member
    // of this Plan", which is a Plan-existence oracle: an attacker who holds one id that is
    // certainly not a member of anything can then tell an existing Plan from a non-existent one
    // by the body alone.
    //
    // The BODY, not the status: both answers are 404, so a status-only assertion is green under
    // either ordering.
    //
    // The role varies too, and that is the part a role-blind loop silently loses: the use case
    // resolves the target through a TERNARY, findTransferTarget() when the requested role is
    // owner and findActiveMember() otherwise. Probing only `admin` exercises one arm and leaves
    // the other's ordering asserted by nothing. The owner probe is listed FIRST deliberately -
    // Pest aborts a test at its first failing assertion, so with it last a member-first ordering
    // would red on an admin probe and the transfer arm would go unobserved.
    $probes = [
        'a non-member asked for as the new owner (the findTransferTarget arm)' => [$outsider->id, MemberRole::Owner],
        'a real active member of this Plan (the control: green under both orderings)' => [$target->id, MemberRole::Admin],
        'a real user who is a member of nothing' => [$outsider->id, MemberRole::Admin],
        'a {user} that is not a uuid' => ['not-a-uuid', MemberRole::Admin],
        'a well-formed uuid that names nobody' => ['0193f0e0-0000-7000-8000-000000000000', MemberRole::Admin],
    ];

    foreach ($probes as $label => [$spelling, $role]) {
        $response = withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$spelling}", [
            'role' => $role->value,
        ]);

        expect($response->status())->toBe(404, "{$label} must answer 404");
        expect($response->json('message'))->toBe(
            'Plan not found.',
            "{$label} must answer the Plan body and never the member body",
        );
    }

    // Tautological against the literal above, and kept for the one force it does carry: someone
    // deleting a probe to "simplify" the loop. Four of the five are load-bearing and the control
    // deliberately is not, so the count is the only thing that notices a deletion.
    expect($probes)->toHaveCount(5, 'all five probes must run, or the loop proves less than it claims');
});

it('answers 404 with the member body when {user} belongs to another Plan', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    $otherOwner = User::factory()->hasProfile()->create();
    $otherPlan = Plan::factory()->create(['owner_id' => $otherOwner->id]);
    $outsider = User::factory()->hasProfile()->create();
    member($otherPlan, $otherOwner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($otherPlan, $outsider, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    // Spec §17.3's IDOR row: 404 PlanMemberNotFound, not 403 and not 422, because a 403
    // confirms the id names a real user - and it does here.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$outsider->id}", [
        'role' => MemberRole::Admin->value,
    ])
        ->assertNotFound()
        ->assertJsonPath('message', 'That person is not a member of this Plan.');
});

it('answers 404 when {user} has left or been removed', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    [, , $access] = signIn($owner);

    // left and removed rows persist for ever, because UNIQUE(plan_id, user_id) forces a
    // re-invite to reuse the row rather than insert a second one (spec §3.5). They grant
    // nothing, and a role change against one must not resurrect it.
    foreach ([MemberStatus::Left, MemberStatus::Removed] as $status) {
        $gone = User::factory()->hasProfile()->create();

        PlanMember::factory()->create([
            'plan_id' => $plan->id,
            'user_id' => $gone->id,
            'role' => MemberRole::Member->value,
            'status' => $status->value,
            'joined_at' => '2026-01-02 10:00:00+00',
        ]);

        $response = withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$gone->id}", [
            'role' => MemberRole::Admin->value,
        ]);

        // Named per iteration, for the reason the owner-as-target loop gives: assertNotFound()
        // and assertJsonPath() carry no message, so a red here would not say which of the two
        // statuses was the one that stopped refusing.
        expect($response->status())->toBe(404, "a {$status->value} membership must answer 404");
        expect($response->json('message'))->toBe(
            'That person is not a member of this Plan.',
            "a {$status->value} membership must answer the member body, not the Plan body",
        );
    }

    expect(PlanMember::query()->where('plan_id', $plan->id)->where('role', MemberRole::Admin->value)->count())
        ->toBe(0, 'neither refused request may have written a role');
});

it('answers 404 rather than 500 for a {user} that is not a uuid', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    [, , $access] = signIn($owner);

    // {user} is attacker-chosen, and `where('user_id', 'not-a-uuid')` against a uuid column is
    // Postgres 22P02, which Handler::prepareException() does not map - so an unguarded route
    // answers 500 {"message":"Server Error"}. The guard is the Str::isUuid() pair already in
    // the repository's own member lookups, not a second one added here.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/not-a-uuid", [
        'role' => MemberRole::Admin->value,
    ])
        ->assertNotFound()
        ->assertJsonPath('message', 'That person is not a member of this Plan.');
});

it('answers 409 with the archived body on an archived Plan', function (): void {
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

    // Spec §5.3: an archived Plan is administratively read-only and the refusal is 409
    // Conflict. Not 404 (the member can see the Plan and GET works, so 404 would lie), not 403
    // (that attributes the refusal to who the caller is, when it is about the Plan's state),
    // not 422 (the request's fields are fine).
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [
        'role' => MemberRole::Admin->value,
    ])
        ->assertStatus(409)
        ->assertJsonPath('message', 'This Plan has been archived.');

    expect($membership->refresh()->role)->toBe(MemberRole::Member->value, 'nothing was written');
});

it('refuses an unverified owner with 403 and the gate body', function (): void {
    $owner = User::factory()->unverified()->hasProfile()->create();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    $token = $owner->createToken('api', ['*'], now()->addMinutes(15));

    // Spec §7.5 gates this route: changing a role acts on another member's behalf and creates
    // state every member sees. The verdict itself is recorded in the partition map in
    // "gates exactly the Plan routes spec 7.5 names, read off the route table", which builds
    // its side from the real route table - so shipping this route ungated reds there too.
    //
    // The body is EnsureEmailIsVerified's own, measured from vendor - spec §5.4's table says
    // "This action is unauthorized." for this case and is wrong (baseline S-27). patchJson()
    // sets Accept: application/json, which is the one header value for which that middleware
    // aborts 403 at all: for Accept: */*, text/html or no Accept header it tries to redirect to
    // the route `verification.notice`, which routes/web.php does not define, and the response
    // is 500 {"message": "Server Error"}.
    withToken($token->plainTextToken)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [
        'role' => MemberRole::Admin->value,
    ])
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified.');
});

it('rejects a role the matrix does not contain with 422', function (): void {
    $owner = userWithPassword();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [
        'role' => 'superuser',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('role');

    // The empty body, separately: `required` rather than `sometimes|required` is what makes an
    // omitted key a 422 rather than a success that changes nothing, and TrimStrings runs before
    // ConvertEmptyStringsToNull, so {"role": "   "} and {"role": ""} both arrive at the
    // validator as present-and-null and take this same branch. There is no third blank shape
    // for a test to probe.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('role');
});

it('keeps the role rule in step with MemberRole', function (): void {
    // The real force, and it is not the assertion restating itself: the rule is a STRING
    // LITERAL, 'in:owner,admin,member', so renaming or adding a MemberRole case leaves it
    // silently stale - the published `enum` and the enforced set then disagree, and the route
    // either refuses a legitimate role with a 422 or accepts one the controller's
    // MemberRole::from() turns into a ValueError and a 500.
    //
    // The literal is deliberate, not laziness: this codebase's measured form for an enumerated
    // rule (LoginRequest's 'in:ios,android,web') and the only form Scramble turns into a
    // published `enum`. Rule::in(array_column(MemberRole::cases(), 'value')) is not statically
    // readable and publishes a bare `string`; a native enum in a rules() array publishes
    // MemberRole's class docblock as the field's description, unconditionally (baseline S-9).
    // This case is the price of the literal.
    $rules = (new ChangeMemberRoleRequest)->rules();
    $expected = 'in:'.implode(',', array_column(MemberRole::cases(), 'value'));

    expect(is_array($rules['role']) && in_array($expected, $rules['role'], true))
        ->toBeTrue("ChangeMemberRoleRequest's role rule must be exactly [{$expected}]");
});

it('transfers ownership, demotes the outgoing owner to admin and writes both activity rows', function (): void {
    $owner = userWithPassword();
    $successor = User::factory()->hasProfile(['display_name' => 'Grace Hopper'])->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    $ownerMembership = member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $successorMembership = member($plan, $successor, MemberRole::Admin, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$successor->id}", [
        'role' => MemberRole::Owner->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.user_id', $successor->id)
        ->assertJsonPath('data.display_name', 'Grace Hopper')
        ->assertJsonPath('data.role', MemberRole::Owner->value);

    // All four of spec §9.3's writes, asserted one by one. The first is the only constrained
    // source of truth (§8.1), so it is the one a reader of "who owns this Plan" must agree with.
    expect($plan->refresh()->owner_id)->toBe($successor->id, '1. plans.owner_id moves');
    expect($successorMembership->refresh()->role)->toBe(MemberRole::Owner->value, '2. the target is promoted');
    expect($ownerMembership->refresh()->role)
        ->toBe(MemberRole::Admin->value, '3. the outgoing owner is demoted to admin, not to member and not left as owner');

    expect(PlanActivity::query()->where('plan_id', $plan->id)->count())
        ->toBe(2, '4. one member.role_changed row per member');

    $promoted = PlanActivity::query()->where('plan_id', $plan->id)->where('subject_id', $successor->id)->sole();
    $demoted = PlanActivity::query()->where('plan_id', $plan->id)->where('subject_id', $owner->id)->sole();

    // toEqual() rather than toBe() for both, for the jsonb key-order reason the first case in
    // this file records at length.
    expect($promoted->metadata)->toEqual(
        ['from' => MemberRole::Admin->value, 'to' => MemberRole::Owner->value],
        "the successor's row records admin to owner",
    );
    expect($demoted->metadata)->toEqual(
        ['from' => MemberRole::Owner->value, 'to' => MemberRole::Admin->value],
        "the outgoing owner's row records owner to admin",
    );
    expect($demoted->actor_id)->toBe($owner->id, 'both rows name the owner who made the transfer');
});

it('answers 404 for a transfer target whose membership is not active', function (): void {
    $owner = userWithPassword();
    $invited = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    PlanMember::factory()->create([
        'plan_id' => $plan->id,
        'user_id' => $invited->id,
        'role' => MemberRole::Member->value,
        'status' => MemberStatus::Invited->value,
        'joined_at' => null,
    ]);

    [, , $access] = signIn($owner);

    // Spec §9.4 row 1, and DeleteAccount::successorFor()'s own comment is the argument:
    // "handing a Plan to someone who was invited and never accepted gives it an owner who
    // cannot see it."
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$invited->id}", [
        'role' => MemberRole::Owner->value,
    ])
        ->assertNotFound()
        ->assertJsonPath('message', 'That person is not a member of this Plan.');

    expect($plan->refresh()->owner_id)->toBe($owner->id, 'ownership did not move');
});

it('answers 404 for a transfer target whose account is soft-deleted or suspended', function (): void {
    $owner = userWithPassword();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);
    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');

    $softDeleted = User::factory()->hasProfile()->create();
    member($plan, $softDeleted, MemberRole::Admin, '2026-01-02 10:00:00+00');
    // The row stays `active`: DeleteAccount marks only the DEPARTING user's memberships left,
    // so a member who deleted their own account still has an active row in everyone else's
    // Plan. That is exactly the gap findTransferTarget()'s account filter closes.
    $softDeleted->delete();

    $suspended = User::factory()->hasProfile()->create(['suspended_at' => now()]);
    member($plan, $suspended, MemberRole::Admin, '2026-01-03 10:00:00+00');

    [, , $access] = signIn($owner);

    // Spec §9.4 row 2. Soft-deleted: the Plan then reaches the day-30 purge, where it is either
    // destroyed or a permanent RESTRICT failure. Suspended: Login::execute() refuses outright,
    // so the new owner cannot sign in to administer the Plan they were just handed. Both
    // sentences are DeleteAccount::successorFor()'s own. Both answer the member body, with no
    // distinction between them and none from an ordinary non-member - telling the owner "that
    // person's account is suspended" would leak an account state (blueprint §126, §154).
    //
    // Two iterations, named: deleting the account filter reds the FIRST of them and says nothing
    // about the second, and the two halves of that filter are independently deletable (the
    // repository's own comment records which mutation isolates which).
    foreach (['soft-deleted' => $softDeleted->id, 'suspended' => $suspended->id] as $label => $ineligible) {
        $response = withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$ineligible}", [
            'role' => MemberRole::Owner->value,
        ]);

        expect($response->status())->toBe(404, "a {$label} transfer target must answer 404");
        expect($response->json('message'))->toBe(
            'That person is not a member of this Plan.',
            "a {$label} transfer target must be indistinguishable from a non-member",
        );
    }

    expect($plan->refresh()->owner_id)->toBe($owner->id, 'ownership did not move');
    expect(PlanActivity::query()->where('plan_id', $plan->id)->count())
        ->toBe(0, 'neither refusal wrote an activity row');
});

it('lets the owner demote an ineligible member even though it cannot promote them', function (): void {
    $owner = userWithPassword();
    $suspended = User::factory()->hasProfile()->create(['suspended_at' => now()]);
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $suspended, MemberRole::Admin, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    // The account filter is findTransferTarget()'s and applies to `role: owner` only. A
    // suspended admin is still a member for every other purpose - spec §8.1 lists them - so
    // demoting them must work. A version that applied the stricter filter to every role change
    // would answer 404 here, which would make a suspended admin un-demotable.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$suspended->id}", [
        'role' => MemberRole::Member->value,
    ])
        ->assertOk()
        ->assertJsonPath('data.role', MemberRole::Member->value);

    expect($membership->refresh()->role)->toBe(MemberRole::Member->value, 'demoted');
});

it('refuses an admin asking to make anyone the owner with 403', function (): void {
    $admin = userWithPassword();
    $owner = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $admin, MemberRole::Admin, '2026-01-02 10:00:00+00');
    $peer = User::factory()->hasProfile()->create();
    member($plan, $peer, MemberRole::Member, '2026-01-03 10:00:00+00');

    [, , $access] = signIn($admin);

    // Spec §9.3: ownership transfer is owner-initiated and nothing else. Both targets, because
    // an admin promoting themselves is the escalation that matters and an admin promoting
    // someone else is the same rule. Named per iteration: the self case is the one that would
    // survive a check written as "the actor may not promote someone else".
    foreach (['themselves' => $admin->id, 'a peer' => $peer->id] as $label => $targetId) {
        $response = withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$targetId}", [
            'role' => MemberRole::Owner->value,
        ]);

        expect($response->status())->toBe(403, "an admin promoting {$label} to owner must be refused");
        expect($response->json('message'))->toBe(
            'This action is unauthorized.',
            "an admin promoting {$label} to owner must carry the generic role-refusal body",
        );
    }

    expect($plan->refresh()->owner_id)->toBe($owner->id, 'ownership did not move');
});

it('writes none of the four when the activity write fails', function (): void {
    $owner = userWithPassword();
    $successor = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    $ownerMembership = member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $successorMembership = member($plan, $successor, MemberRole::Admin, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    // A model hook that throws, not a mock: the activity writer is reached through a
    // final readonly class and Mockery refuses such a class outright, and DeleteAccountTest's
    // "does nothing at all if any step fails" records why the hook is the better instrument
    // anyway - the same injection point as a mock, one frame deeper, and strictly stronger
    // because it also aborts the INSERT a real failure would abort. Nothing pops the hook: the
    // application, and with it the event dispatcher, is rebuilt for every test.
    //
    // The hook throws and CHANGES NOTHING ELSE. That is what makes the red below attributable:
    // the first three writes genuinely execute, so the only thing that can explain an absent
    // owner_id move is the rollback. A mutation that both removed the transaction and corrupted
    // an argument would red identically with the transaction present, and would prove nothing.
    PlanActivity::saving(function (): void {
        throw new RuntimeException('the activity write failed');
    });

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$successor->id}", [
        'role' => MemberRole::Owner->value,
    ])->assertStatus(500);

    // Spec §9.3: the transaction writes four things, and all four together or none. This is the
    // "or none" half, and it is the half no status assertion can see - a 500 arrives whether the
    // writes rolled back or committed.
    expect($plan->refresh()->owner_id)->toBe($owner->id, 'plans.owner_id rolled back');
    expect($successorMembership->refresh()->role)->toBe(MemberRole::Admin->value, "the target's role rolled back");
    expect($ownerMembership->refresh()->role)->toBe(MemberRole::Owner->value, "the outgoing owner's role rolled back");
    expect(PlanActivity::query()->where('plan_id', $plan->id)->count())->toBe(0, 'no activity row survived');
});

it('writes neither the role nor the activity row when a plain role change fails', function (): void {
    $owner = userWithPassword();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    [, , $access] = signIn($owner);

    // Added because the plain role change has its OWN DB::transaction(), which the transfer case
    // above cannot reach: every other case in this file either succeeds or refuses before the
    // first write, so without this one that second transaction is a guard nothing in the suite
    // can observe - deletable, green. Measured, both directions: deleting the non-transfer
    // branch's DB::transaction() reds THIS case and nothing else in the suite, and it reds on the
    // partial state (a role written on a request that answered 500) rather than on the status.
    // The instrument is the same model hook as the transfer case, for the same reason.
    PlanActivity::saving(function (): void {
        throw new RuntimeException('the activity write failed');
    });

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [
        'role' => MemberRole::Admin->value,
    ])->assertStatus(500);

    expect($membership->refresh()->role)->toBe(MemberRole::Member->value, 'the role change rolled back');
    expect(PlanActivity::query()->where('plan_id', $plan->id)->count())->toBe(0, 'no activity row survived');
});

it('refuses a transfer loudly when plans.owner_id disagrees with the owner row', function (): void {
    $claimant = userWithPassword();
    $realOwner = User::factory()->hasProfile()->create();
    $successor = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $realOwner->id]);

    member($plan, $claimant, MemberRole::Owner, '2026-01-01 10:00:00+00');
    member($plan, $realOwner, MemberRole::Admin, '2026-01-02 10:00:00+00');
    member($plan, $successor, MemberRole::Member, '2026-01-03 10:00:00+00');

    [, , $access] = signIn($claimant);

    // Spec §9.1's invariant deliberately violated by the fixture: two sources of truth
    // disagreeing. plan_members.role carries no constraint of any kind, so the database permits
    // this; only the application can refuse it. 500 rather than a quiet 403, because a Plan in
    // this state is a bug and the one useful response is a reported exception naming the
    // invariant.
    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$successor->id}", [
        'role' => MemberRole::Owner->value,
    ])
        ->assertStatus(500)
        ->assertJsonPath('message', 'Server Error');

    expect($plan->refresh()->owner_id)->toBe($realOwner->id, 'nothing was written');
});

it('refuses loudly, and writes nothing, for an active member with no joined_at', function (): void {
    $owner = userWithPassword();
    $target = User::factory()->hasProfile()->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01 10:00:00+00');
    $membership = member($plan, $target, MemberRole::Member, '2026-01-02 10:00:00+00');

    // Baseline S-37's row, written the one way the application itself never writes it: a raw
    // UPDATE, because PlanMemberFactory::active() and every Wave 2 writer set joined_at. It is
    // reachable in the suite, though - PlanMemberFactory's default is 'joined_at' => null, so
    // any fixture that overrides only `status` produces exactly this row.
    DB::table('plan_members')->where('id', $membership->id)->update(['joined_at' => null]);

    [, , $access] = signIn($owner);

    withToken($access)->patchJson("/api/v1/plans/{$plan->id}/members/{$target->id}", [
        'role' => MemberRole::Admin->value,
    ])
        ->assertStatus(500)
        ->assertJsonPath('message', 'Server Error');

    // The point of the test is this line, not the status: the invariant is checked before the
    // first write, so a corrupt row costs a 500 and changes nothing. The status alone proves
    // nothing about WHERE the refusal happened, because the repository's own view builder
    // raises its own exception for the same row after the writes have already committed.
    expect($membership->refresh()->role)->toBe(MemberRole::Member->value, 'nothing was written');
});
