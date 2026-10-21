<?php

declare(strict_types=1);

use App\Application\Plans\RecordActivity;
use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanStatus;
use App\Domain\Plans\Visibility;
use App\Infrastructure\Notifications\Mail\PlanInviteMail;
use App\Infrastructure\Outbox\OutboxDispatcher;
use App\Models\Plan;
use App\Models\PlanActivity;
use App\Models\PlanInvite;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

// No RateLimiter::clear() anywhere in this file. The real middleware key is
// md5($limiterName.$limit->key), so clearing 'plan-invites' by name clears nothing; and nothing
// is needed, because CACHE_STORE=array plus the per-test application rebuild means limiter state
// never crosses a test boundary. Within one test it DOES persist, which is why both rate-limit
// cases below exhaust inside a single it() block.
beforeEach(function (): void {
    Mail::fake();
});

it('issues a link-only invite, returns the plaintext once, and writes no membership', function (): void {
    // Hash::make('correct horse battery staple') rather than the UserFactory default, and it is
    // load-bearing: signIn() posts that fixed password to the real POST /auth/login and asserts
    // 200 on the way through, while UserFactory seeds Hash::make('password') - so
    // signIn(User::factory()->hasProfile()->create()) fails INSIDE signIn() with a 401 and the
    // test's own subject is never reached. The shipped precedent is
    // tests/Feature/Auth/PasswordResetTest.php ("signIn() expects this password, so the user is
    // seeded with it rather than patched after"). Where the display name does not have to be a
    // known string, tests/Pest.php::userWithPassword() is the shorter form and is used below.
    $owner = User::factory()->hasProfile(['display_name' => 'Denis Chweya'])
        ->create(['password_hash' => Hash::make('correct horse battery staple')]);
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    $response = withToken($access)->postJson("/api/v1/plans/{$plan->id}/invites", [])
        ->assertCreated()
        ->assertJsonPath('data.email', null)
        ->assertJsonPath('data.role', MemberRole::Member->value)
        ->assertJsonPath('data.accepted_at', null)
        ->assertJsonPath('data.revoked_at', null)
        ->assertJsonPath('data.invited_by_name', 'Denis Chweya');

    $token = $response->json('data.token');

    expect($token)->toBeString();
    expect($token)->toMatch('/^[0-9a-f]{64}$/');

    $invite = PlanInvite::query()->sole();

    expect($invite->token_hash)->toBe(hash('sha256', (string) $token));
    expect($invite->email)->toBeNull();
    expect($invite->invited_by)->toBe($owner->id);
    expect($response->json('data.id'))->toBe($invite->id);

    // §10.5's decision, and the assertion that makes it real: issuing an invite NEVER creates a
    // plan_members row. plan_invites is the entire pending state. A pending membership row would
    // need a user_id, so it could only exist for an address that already belongs to an account -
    // which turns the member list into an account-existence oracle any owner or admin could
    // query against any address, repeatedly. The API goes to deliberate lengths to avoid exactly
    // that: POST /auth/forgot-password returns a byte-identical empty 202 for a known address,
    // an unknown one and a suspended account.
    //
    // The owner's own row is the only one, so this is 1 rather than 0.
    expect(PlanMember::query()->count())->toBe(1);
    expect(PlanMember::query()->where('user_id', $owner->id)->count())->toBe(1);

    // Link-only: no outbox event, no mail.
    expect(DB::table('outbox_events')->where('event_type', 'plan.invited')->count())->toBe(0);
    Mail::assertNothingSent();

    $activity = PlanActivity::query()->where('activity_type', ActivityType::MemberInvited->value)->sole();

    expect($activity->plan_id)->toBe($plan->id);
    expect($activity->actor_id)->toBe($owner->id);
    // Against the constant, not the literal: the VALUE is pinned by the section that owns
    // RecordActivity (its own test asserts SUBJECT_INVITE === 'PlanInvite'), and what this file
    // is entitled to assert is that this call site passes that constant. The independent half of
    // the claim is the subject_id assertion below, which no constant can satisfy.
    expect($activity->subject_type)->toBe(RecordActivity::SUBJECT_INVITE);
    expect($activity->subject_id)->toBe($invite->id);

    // toEqual() and NOT toBe(): plan_activity.metadata is `jsonb`, which stores an object's
    // keys in its own normalised order (shortest first, then bytewise) rather than insertion
    // order, so this row reads back as {"role": ..., "channel": ...} and an identity comparison
    // on an associative array fails on key ORDER alone - an assertion about Postgres's storage
    // format rather than about the metadata. toEqual() compares with ==, which for arrays
    // requires the same key/value pairs and ignores their order. Same measured reason
    // ChangeMemberRoleTest's from/to assertions give for the same column.
    expect($activity->metadata)->toEqual(['channel' => 'link', 'role' => 'member']);
});

it('issues an emailed invite with an encrypted token in the outbox and no address in it', function (): void {
    // A trailing slash on purpose, so rtrim() is exercised: blueprint §132's format is
    // https://app.example.com/invite/{token} with exactly one slash.
    config(['app.web_url' => 'https://app.example.test/']);

    $owner = User::factory()->hasProfile(['display_name' => 'Denis Chweya'])
        ->create(['password_hash' => Hash::make('correct horse battery staple')]);
    $plan = Plan::factory()->create(['owner_id' => $owner->id, 'title' => 'Ski trip']);
    member($plan, $owner, MemberRole::Owner, '2026-01-01T00:00:00+00:00');
    [, , $access] = signIn($owner);

    $response = withToken($access)
        ->postJson("/api/v1/plans/{$plan->id}/invites", ['email' => 'ada@example.test'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'ada@example.test');

    $token = (string) $response->json('data.token');
    $invite = PlanInvite::query()->sole();

    // Read as raw jsonb text, not through the model's array cast, because the claim is about
    // what is PERSISTED: OutboxDispatcher sets published_at and NEVER deletes the row, so
    // anything readable here is readable by anyone with database access for ever.
    $raw = (string) DB::table('outbox_events')->where('event_type', 'plan.invited')->value('payload');

    // Blueprint §142.7: no credential in plaintext. This was caught in Wave 1a Task 6 before it
    // shipped, with a false rationale ("the row is deleted once published") replaced by the real
    // one. Wave 2 must not rediscover it.
    expect($raw)->not->toContain($token);

    // And no address either. §14.1 clause 2 nulls plan_invites.email for an unaccepted invite
    // addressed to a deleted user, which the outbox payload would silently outlive, so the
    // address is not copied into it at all - the handler reads it off the invite row.
    expect($raw)->not->toContain('ada@example.test');

    $payload = json_decode($raw, true);

    expect($payload)->toBeArray();
    expect(array_keys((array) $payload))->toEqualCanonicalizing(['invite_id', 'token']);
    expect($payload['invite_id'])->toBe($invite->id);
    expect(Crypt::decryptString((string) $payload['token']))->toBe($token);

    expect(DB::table('outbox_events')->where('event_type', 'plan.invited')->count())->toBe(1);

    // The activity row names the channel and the role and NEVER the address: the feed is read by
    // every member, and an invited address is personal data about someone who is not a member.
    $activity = PlanActivity::query()->where('activity_type', ActivityType::MemberInvited->value)->sole();

    // toEqual(), for the jsonb key-order reason the first test in this file records at length.
    expect($activity->metadata)->toEqual(['channel' => 'email', 'role' => 'member']);
    expect(json_encode($activity->metadata))->not->toContain('ada@example.test');

    app(OutboxDispatcher::class)->dispatchPending();

    Mail::assertSent(PlanInviteMail::class, function (PlanInviteMail $mail) use ($token): bool {
        expect($mail->inviteUrl)->toBe('https://app.example.test/invite/'.$token);
        expect($mail->planTitle)->toBe('Ski trip');
        expect($mail->inviterName)->toBe('Denis Chweya');
        expect($mail->token)->toBe($token);

        return true;
    });
});

it('normalises a mixed-case address instead of hitting the database CHECK', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    // plan_invites carries a byte-exact CHECK: email IS NULL OR email = lower(email). An
    // un-normalised address is a 23514 constraint violation, which is a QueryException, which is
    // absent from Handler::prepareException()'s table and therefore renders as
    // 500 {"message": "Server Error"} - not a 422.
    withToken($access)->postJson("/api/v1/plans/{$plan->id}/invites", ['email' => 'Ada@Example.TEST'])
        ->assertCreated()
        ->assertJsonPath('data.email', 'ada@example.test');

    expect(PlanInvite::query()->sole()->email)->toBe('ada@example.test');
});

it('answers 422 rather than 500 for an address Laravel accepts and the value object does not', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    // Wave 1a Task 3's finding I1: email:rfc and Email::fromString()'s
    // filter_var(FILTER_VALIDATE_EMAIL) gate are different validators, and the controller calls
    // Email::fromString() with no try/catch - so every address in the gap left the request as a
    // 500. These four are the measured examples.
    foreach (['ada@localhost', 'ada@example', 'ada(comment)@example.test', '"ada bob"@example.test'] as $address) {
        withToken($access)->postJson("/api/v1/plans/{$plan->id}/invites", ['email' => $address])
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('email');
    }

    expect(PlanInvite::query()->count())->toBe(0);
});

it('lets an owner mint an admin invite and refuses an admin minting one', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    $admin = userWithPassword();
    member($plan, $admin, MemberRole::Admin, '2026-01-02T00:00:00+00:00');

    [, , $ownerAccess] = signIn($owner);
    withToken($ownerAccess)->postJson("/api/v1/plans/{$plan->id}/invites", ['role' => 'admin'])
        ->assertCreated()
        ->assertJsonPath('data.role', 'admin');

    forgetAuthenticatedUser();

    [, , $adminAccess] = signIn($admin);

    // `assign admins` is owner-only (§3.2), so an admin who can mint an admin-role invite has
    // promoted someone with an extra step. 403, not 404: this admin can plainly see the Plan.
    withToken($adminAccess)->postJson("/api/v1/plans/{$plan->id}/invites", ['role' => 'admin'])
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    // But a member-role invite from the same admin is fine - `invite members` is theirs.
    withToken($adminAccess)->postJson("/api/v1/plans/{$plan->id}/invites", ['role' => 'member'])
        ->assertCreated()
        ->assertJsonPath('data.role', 'member');

    expect(PlanInvite::query()->where('role', 'admin')->count())->toBe(1);
});

it('rejects an owner-role invite at validation, never at the policy', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    // `owner` is absent from the in: list, so this is a 422 before any use case runs. An
    // owner-role invite would be an ownership transfer by the back door, bypassing §9.3.
    withToken($access)->postJson("/api/v1/plans/{$plan->id}/invites", ['role' => 'owner'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('role');

    expect(PlanInvite::query()->count())->toBe(0);
});

it('refuses a plain member with 403 and a non-member with the Plan-not-found 404', function (): void {
    // The owner never signs in here, so they stay on the factory default: only a user who goes
    // through signIn() needs userWithPassword()'s password.
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    $plainMember = userWithPassword();
    member($plan, $plainMember, MemberRole::Member, '2026-01-02T00:00:00+00:00');
    $stranger = userWithPassword();

    [, , $memberAccess] = signIn($plainMember);
    withToken($memberAccess)->postJson("/api/v1/plans/{$plan->id}/invites", [])
        ->assertForbidden()
        ->assertJsonPath('message', 'This action is unauthorized.');

    forgetAuthenticatedUser();

    [, , $strangerAccess] = signIn($stranger);

    // 404 and not 403, because a 403 confirms the Plan exists and that is what leaks to a
    // non-member. The body is PlanNotVisible's, which says nothing about this Plan.
    withToken($strangerAccess)->postJson("/api/v1/plans/{$plan->id}/invites", [])
        ->assertNotFound()
        ->assertJsonPath('message', 'Plan not found.');

    expect(PlanInvite::query()->count())->toBe(0);
});

it('refuses an archived Plan with 409 and a private Plan with its own 409', function (): void {
    $owner = userWithPassword();

    $archived = ownedPlan($owner);
    DB::table('plans')->where('id', $archived->id)
        ->update(['status' => PlanStatus::Archived->value, 'archived_at' => now()]);

    $private = ownedPlan($owner);
    DB::table('plans')->where('id', $private->id)
        ->update(['visibility' => Visibility::Private->value]);

    [, , $access] = signIn($owner);

    withToken($access)->postJson("/api/v1/plans/{$archived->id}/invites", [])
        ->assertStatus(409)
        ->assertJsonPath('message', 'This Plan has been archived.');

    // Two different 409s, and the distinction is the point: a Plan that is private is not a Plan
    // that is archived, and a client that cannot tell them apart cannot say anything useful.
    withToken($access)->postJson("/api/v1/plans/{$private->id}/invites", [])
        ->assertStatus(409)
        ->assertJsonPath('message', 'This Plan is private, so nobody else can be invited to it.');

    expect(PlanInvite::query()->count())->toBe(0);
});

it('refuses an unverified owner with the verified gate', function (): void {
    // userWithPassword(attributes: [...]) sets exactly what UserFactory::unverified() sets, and
    // keeps the password signIn() posts. An unverified user can still SIGN IN - nothing in the
    // login path consults email_verified_at - which is what makes this test reach the gate.
    $owner = userWithPassword(attributes: ['email_verified_at' => null]);
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    // The body is EnsureEmailIsVerified's own, not AuthorizationException's default - measured,
    // and spec §5.4's table says otherwise and is wrong. The 403 is also conditional on the
    // Accept header: postJson() sets Accept: application/json, which is the one value for which
    // the middleware aborts 403 at all. With Accept: */*, no Accept, or text/html, the same
    // request is a 500, because the middleware falls through to
    // Redirect::guest(URL::route('verification.notice')) and routes/web.php is deliberately
    // empty. That is a known, measured gap in the gate, not this route's problem to fix, and it
    // is recorded here so nobody "fixes" this assertion by loosening it.
    withToken($access)->postJson("/api/v1/plans/{$plan->id}/invites", [])
        ->assertForbidden()
        ->assertJsonPath('message', 'Your email address is not verified.');

    expect(PlanInvite::query()->count())->toBe(0);
});

it('rate limits invites to twenty an hour for one Plan', function (): void {
    $owner = userWithPassword();
    $plan = ownedPlan($owner);
    [, , $access] = signIn($owner);

    for ($i = 1; $i <= 20; $i++) {
        withToken($access)->postJson("/api/v1/plans/{$plan->id}/invites", [])->assertCreated();
    }

    $response = withToken($access)->postJson("/api/v1/plans/{$plan->id}/invites", [])
        ->assertStatus(429);

    $rateLimitHeaders = array_values(array_filter(
        array_keys($response->headers->all()),
        fn (string $name): bool => $name === 'retry-after' || str_starts_with($name, 'x-ratelimit-'),
    ));
    sort($rateLimitHeaders);

    // Four, not three: X-RateLimit-Reset is set in the same branch as Retry-After in
    // ThrottleRequests::getHeaders() and cannot be separated from it.
    expect($rateLimitHeaders)->toBe([
        'retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset',
    ]);

    // 20, the per-PLAN limit - which is how this test proves WHICH of the two limits fired. The
    // per-user limit is 50, so an X-RateLimit-Limit of 50 here would mean the 429 came from the
    // wrong bucket, and a status assertion alone could not tell.
    $response->assertHeader('X-RateLimit-Limit', '20')
        ->assertHeader('X-RateLimit-Remaining', '0');

    expect(PlanInvite::query()->count())->toBe(20);
});

it('rate limits invites to fifty a day for one user across several Plans, and not for a second user', function (): void {
    $owner = userWithPassword();
    $a = ownedPlan($owner);
    $b = ownedPlan($owner);
    $c = ownedPlan($owner);
    [, , $access] = signIn($owner);

    // Twenty to each of two Plans and ten to a third: fifty requests, and no Plan sees more than
    // twenty, so the 20/hour/plan limit is never the one that fires. A test that fired fifty-one
    // invites at ONE Plan would hit the per-plan limit at request twenty-one and prove nothing
    // about this one while reading as though it covered both.
    foreach ([[$a, 20], [$b, 20], [$c, 10]] as [$plan, $count]) {
        for ($i = 1; $i <= $count; $i++) {
            withToken($access)->postJson("/api/v1/plans/{$plan->id}/invites", [])->assertCreated();
        }
    }

    expect(PlanInvite::query()->count())->toBe(50);

    $response = withToken($access)->postJson("/api/v1/plans/{$c->id}/invites", [])->assertStatus(429);

    // 50, the per-USER limit. The eleventh invite to Plan C is nowhere near twenty, so this 429
    // can only have come from the per-user bucket.
    $response->assertHeader('X-RateLimit-Limit', '50');

    // And the key is the user, not the IP. This is the half no status assertion can see: every
    // request in this suite arrives with REMOTE_ADDR = 127.0.0.1, so under an IP key the second
    // user below would share the exhausted bucket and get a 429 too.
    forgetAuthenticatedUser();

    $second = userWithPassword();
    $theirPlan = ownedPlan($second);
    [, , $secondAccess] = signIn($second);

    withToken($secondAccess)->postJson("/api/v1/plans/{$theirPlan->id}/invites", [])->assertCreated();

    expect(PlanInvite::query()->count())->toBe(51);
});

it('requires authentication', function (): void {
    $plan = ownedPlan(User::factory()->hasProfile()->create());

    postJson("/api/v1/plans/{$plan->id}/invites")->assertUnauthorized();

    expect(PlanInvite::query()->count())->toBe(0);
});
