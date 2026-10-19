<?php

declare(strict_types=1);

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Domain\Plans\Visibility;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('writes the Plan, the owner active membership and one plan.created row', function (): void {
    $user = userWithPassword();
    [, , $access] = signIn($user);

    withToken($access)->postJson('/api/v1/plans', [
        'title' => 'Dinner in Nairobi',
        'description' => 'Pizza and a film',
        'timezone' => 'Africa/Nairobi',
    ])
        ->assertCreated()
        ->assertJsonPath('data.title', 'Dinner in Nairobi')
        ->assertJsonPath('data.description', 'Pizza and a film')
        ->assertJsonPath('data.timezone', 'Africa/Nairobi')
        ->assertJsonPath('data.status', PlanStatus::Active->value)
        ->assertJsonPath('data.visibility', Visibility::InviteOnly->value)
        ->assertJsonPath('data.owner_id', $user->id)
        // 1 and 'owner': the creator's own membership row is what makes these two true, and
        // they are the cheapest proof from the outside that step 2 of §7.2 ran.
        ->assertJsonPath('data.member_count', 1)
        ->assertJsonPath('data.viewer_role', MemberRole::Owner->value)
        ->assertJsonPath('data.archived_at', null);

    $plan = Plan::query()->sole();

    expect($plan->owner_id)->toBe($user->id, 'owner_id is the actor, never a request field');
    expect($plan->group_id)->toBeNull('§12.2: nothing in Cycle 1 writes plans.group_id');
    expect($plan->cover_image_path)->toBeNull('no upload path exists, spec §15');

    $membership = PlanMember::query()->sole();

    expect($membership->plan_id)->toBe($plan->id);
    expect($membership->user_id)->toBe($user->id);
    expect($membership->role)->toBe(MemberRole::Owner->value);
    // Asserted explicitly because the Postgres column default is 'invited': plan_members is
    // $guarded = ['*'], which blocks a SUPPLIED attribute and does nothing at all about an
    // OMITTED one, so "the application never writes invited" cannot be read off the model.
    expect($membership->status)->toBe(MemberStatus::Active->value, 'CreatePlan must assign status explicitly');
    // Non-null because a keyset page over (joined_at, id) drops a NULL-joined_at row from every
    // members page after the first, and the column is nullable with no CHECK and no index.
    expect($membership->joined_at)->not->toBeNull('an active membership must carry joined_at');

    $activity = PlanActivity::query()->sole();

    expect($activity->plan_id)->toBe($plan->id);
    expect($activity->actor_id)->toBe($user->id);
    expect($activity->activity_type)->toBe(ActivityType::PlanCreated->value);
    expect($activity->subject_type)->toBeNull('a plan.* row needs no subject - plan_id is the subject');
    expect($activity->subject_id)->toBeNull();
    expect($activity->metadata)->toBe([]);
    expect($activity->created_at)->not->toBeNull('plan_activity.created_at is NOT NULL and $timestamps = false');
});

it('stores a null description when none is sent', function (): void {
    $user = userWithPassword();
    [, , $access] = signIn($user);

    withToken($access)->postJson('/api/v1/plans', [
        'title' => 'Untitled business',
        'timezone' => 'Europe/London',
    ])
        ->assertCreated()
        ->assertJsonPath('data.description', null);

    expect(Plan::query()->sole()->description)->toBeNull();
});

it('ignores owner_id, status, visibility and group_id in the request body', function (): void {
    $user = userWithPassword();
    $someoneElse = User::factory()->hasProfile()->create();
    [, , $access] = signIn($user);

    // §12.2 is a refusal, and this is the behavioural half of it: the four fields are absent
    // from CreatePlanRequest::rules(), so validated() cannot carry them, and CreatePlan assigns
    // every column by name.
    //
    // Be clear about what this case can and cannot see, because the two halves are not
    // interchangeable. It pins the BEHAVIOUR, and the behaviour survives a group_id being added
    // back to rules() - CreatePlan still would not write it - so this case would stay green
    // through exactly the change §12.2 forbids. The assertion that goes red for that change is
    // "publishes no group_id on the Plan write surface" in OpenApiContractTest, because the
    // decision was that the field is ABSENT FROM THE CONTRACT, not merely ineffective.
    withToken($access)->postJson('/api/v1/plans', [
        'title' => 'Dinner',
        'timezone' => 'Europe/London',
        'owner_id' => $someoneElse->id,
        'status' => PlanStatus::Archived->value,
        'visibility' => Visibility::Private->value,
        'group_id' => '019360c6-0000-7000-8000-000000000000',
    ])->assertCreated();

    $plan = Plan::query()->sole();

    expect($plan->owner_id)->toBe($user->id);
    expect($plan->status)->toBe(PlanStatus::Active->value);
    expect($plan->visibility)->toBe(Visibility::InviteOnly->value);
    expect($plan->group_id)->toBeNull();
});

it('rejects a missing title, an over-long title, a blank title and a non-IANA timezone', function (): void {
    $user = userWithPassword();
    [, , $access] = signIn($user);

    withToken($access)->postJson('/api/v1/plans', ['timezone' => 'Europe/London'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('title');

    withToken($access)->postJson('/api/v1/plans', [
        'title' => str_repeat('a', 256),
        'timezone' => 'Europe/London',
    ])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('title');

    // "   " reaches `required` as null, and this is framework behaviour rather than anything in
    // this task: TrimStrings and ConvertEmptyStringsToNull are GLOBAL middleware (indices 6 and
    // 7 of the kernel's 8), not members of the `api` group - measured, because the `api` group
    // is [SubstituteBindings] and nothing else. So no prepareForValidation() of ours is
    // involved, and none should be added.
    withToken($access)->postJson('/api/v1/plans', ['title' => '   ', 'timezone' => 'Europe/London'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('title');

    withToken($access)->postJson('/api/v1/plans', ['title' => 'Dinner', 'timezone' => 'Mars/Olympus_Mons'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('timezone');

    withToken($access)->postJson('/api/v1/plans', ['title' => 'Dinner'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('timezone');

    expect(Plan::query()->count())->toBe(0, 'no 422 may leave a row behind');
});

it('refuses an unverified user with 403 and writes nothing', function (): void {
    $user = userWithPassword(attributes: ['email_verified_at' => null]);
    [, , $access] = signIn($user);

    // The body is EnsureEmailIsVerified's own - abort(403, 'Your email address is not
    // verified.') - and not AuthorizationException's 'This action is unauthorized.'. Measured:
    // spec §5.4's table states the wrong sentence for this row.
    withToken($access)->postJson('/api/v1/plans', ['title' => 'Dinner', 'timezone' => 'Europe/London'])
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified.');

    expect(Plan::query()->count())->toBe(0);
    expect(PlanMember::query()->count())->toBe(0);
    expect(PlanActivity::query()->count())->toBe(0);
});

it('limits one user to thirty an hour and leaves a second user on the same IP alone', function (): void {
    // One it() block, deliberately: phpunit.xml pins CACHE_STORE=array and the application is
    // rebuilt per test, so limiter state does not survive a test boundary - splitting the
    // exhaustion and the assertion across two blocks would silently reset the budget and the
    // second block would assert against a fresh limiter. For the same reason there is no
    // RateLimiter::clear() anywhere here: the real key is md5('plans-create'.$userId), so
    // clearing by name clears nothing, and nothing needs clearing.
    $first = userWithPassword();
    [, , $firstAccess] = signIn($first);

    for ($i = 1; $i <= 30; $i++) {
        withToken($firstAccess)->postJson('/api/v1/plans', [
            'title' => "Plan {$i}",
            'timezone' => 'Europe/London',
        ])->assertCreated();
    }

    $refused = withToken($firstAccess)->postJson('/api/v1/plans', [
        'title' => 'Plan 31',
        'timezone' => 'Europe/London',
    ])->assertStatus(429);

    // Four headers, not three. ThrottleRequests::getHeaders() sets Retry-After and
    // X-RateLimit-Reset inside the same `if (! is_null($retryAfter))`, so the two cannot be
    // separated - which is why spec §13's "the full header set" of three is one short. The
    // first assertion of a 429 header anywhere in this suite.
    $refused->assertHeader('X-RateLimit-Limit', '30');
    $refused->assertHeader('X-RateLimit-Remaining', '0');
    // Presence only, and deliberately: Retry-After is availableIn(), counted from the FIRST of
    // the thirty requests above, so its exact value shrinks by however long those thirty
    // round trips took. An exact 3600 here would be a flake, not an assertion.
    $refused->assertHeader('Retry-After');
    $refused->assertHeader('X-RateLimit-Reset');

    expect(Plan::query()->count())->toBe(30, 'the 31st request must not have written a Plan');

    // Mandatory. Sanctum's guard caches the resolved user for the whole test, so without this
    // the second user's request authenticates as the FIRST user, hits the same bucket, and this
    // test fails for a reason that has nothing to do with the limiter's key.
    forgetAuthenticatedUser();

    $second = userWithPassword();
    [, , $secondAccess] = signIn($second);

    // The whole point of the test. Every request in the suite arrives with
    // REMOTE_ADDR = 127.0.0.1, so if the closure keyed on the IP both users would share one
    // bucket and this would be a 429 - and a 429 from the wrong key is indistinguishable from
    // a 429 from the right one by status alone.
    withToken($secondAccess)->postJson('/api/v1/plans', [
        'title' => 'A different person entirely',
        'timezone' => 'Europe/London',
    ])->assertCreated();
});

it('requires authentication', function (): void {
    postJson('/api/v1/plans', ['title' => 'Dinner', 'timezone' => 'Europe/London'])
        ->assertUnauthorized();

    expect(Plan::query()->count())->toBe(0);
});
