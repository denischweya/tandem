<?php

declare(strict_types=1);

use App\Application\Plans\InviteNotUsable;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;

use function Pest\Laravel\getJson;
use function Pest\Laravel\withServerVariables;

uses(RefreshDatabase::class);

// No RateLimiter::clear() anywhere in this file, and that is the positive rule rather than an
// omission. The middleware's real cache key is md5($limiterName.$limit->key), so clearing by
// the bare name 'invite-lookup' clears nothing - and nothing is needed in its place, because
// phpunit.xml pins CACHE_STORE=array and the application is rebuilt per test, so limiter state
// never crosses a test boundary. The inverse matters here: WITHIN one test the array store does
// persist, which is why the rate-limit case below exhausts inside a single it() block.

it('previews an invite with the Plan, the inviter and a count, and nothing else', function (): void {
    $owner = User::factory()->hasProfile(['display_name' => 'Denis Chweya'])->create();
    $plan = Plan::factory()->create(['owner_id' => $owner->id, 'title' => 'Ski trip']);
    member($plan, $owner, MemberRole::Owner, '2026-01-01T00:00:00+00:00');
    member($plan, User::factory()->hasProfile()->create(), MemberRole::Member, '2026-01-02T00:00:00+00:00');

    // Three rows that must NOT be counted: active is the only status that grants anything, and
    // member_count counts active rows only, matching the member list.
    PlanMember::factory()->create([
        'plan_id' => $plan->id, 'user_id' => User::factory()->create()->id,
        'role' => MemberRole::Member->value, 'status' => MemberStatus::Invited->value, 'joined_at' => null,
    ]);
    PlanMember::factory()->create([
        'plan_id' => $plan->id, 'user_id' => User::factory()->create()->id,
        'role' => MemberRole::Member->value, 'status' => MemberStatus::Left->value, 'joined_at' => '2026-01-03T00:00:00+00:00',
    ]);
    PlanMember::factory()->create([
        'plan_id' => $plan->id, 'user_id' => User::factory()->create()->id,
        'role' => MemberRole::Member->value, 'status' => MemberStatus::Removed->value, 'joined_at' => '2026-01-04T00:00:00+00:00',
    ]);

    [, $token] = invited($plan, $owner, MemberRole::Member, 'ada@example.test');

    $response = getJson("/api/v1/invites/{$token}")
        ->assertOk()
        ->assertJsonPath('data.plan_id', $plan->id)
        ->assertJsonPath('data.plan_title', 'Ski trip')
        ->assertJsonPath('data.inviter_name', 'Denis Chweya')
        ->assertJsonPath('data.member_count', 2);

    // The key set, exactly. This is the half of §10.3 that the field assertions above cannot
    // see: the decision is that the preview is MINIMAL, so a field ADDED to it - a description,
    // a member list, the invited address, the Plan's status - must fail here. assertJsonStructure
    // would pass for a superset.
    expect(array_keys((array) $response->json('data')))
        ->toEqualCanonicalizing(['plan_id', 'plan_title', 'inviter_name', 'member_count']);
});

it('needs no authentication at all', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [, $token] = invited($plan, $owner, MemberRole::Member);

    // No withToken(). Spec §11's third case has a brand-new user see the Plan BEFORE they have
    // an account, so a 401 here would make that case unsatisfiable.
    getJson("/api/v1/invites/{$token}")->assertOk();
});

it('reports a null inviter name when the inviter no longer resolves', function (): void {
    // Reachable through this route: plan_invites.invited_by is nullable with nullOnDelete, and
    // separately a soft-deleted inviter does not resolve through User's SoftDeletes scope. The
    // invite is still a live credential either way, so the honest answer is a null name rather
    // than a 404 or invented copy - and publishing inviter_name as non-nullable would make the
    // correction a visible contract change later, because tandem-shared's WireEquivalent proofs
    // are symmetric.
    //
    // $inviter->delete() soft-deletes the row directly and never runs DeleteAccount, so §14.1
    // clause 1 (revoke a departing user's outstanding invites) is not involved and this test
    // stays valid once that clause ships. Do not "fix" it by routing through DELETE /me.
    $inviter = User::factory()->hasProfile()->create();
    $plan = ownedPlan($inviter);
    [, $token] = invited($plan, $inviter, MemberRole::Member);

    $inviter->delete();

    getJson("/api/v1/invites/{$token}")
        ->assertOk()
        ->assertJsonPath('data.inviter_name', null)
        ->assertJsonPath('data.plan_id', $plan->id);
});

it('answers every unusable invite link with one byte-identical body', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    [, $valid] = invited($plan, $owner, MemberRole::Member);
    [$expiredInvite, $expired] = invited($plan, $owner, MemberRole::Member);
    [$revokedInvite, $revoked] = invited($plan, $owner, MemberRole::Member);
    [$acceptedInvite, $accepted] = invited($plan, $owner, MemberRole::Member);

    // Driven straight at the columns, not through endpoints that do not exist yet in this task.
    DB::table('plan_invites')->where('id', $expiredInvite->id)->update(['expires_at' => now()->subMinute()]);
    DB::table('plan_invites')->where('id', $revokedInvite->id)->update(['revoked_at' => now()]);
    DB::table('plan_invites')->where('id', $acceptedInvite->id)
        ->update(['accepted_at' => now(), 'accepted_by' => $owner->id]);

    $archivedPlan = ownedPlan($owner);
    [, $archived] = invited($archivedPlan, $owner, MemberRole::Member);
    DB::table('plans')->where('id', $archivedPlan->id)
        ->update(['status' => PlanStatus::Archived->value, 'archived_at' => now()]);

    $responses = [
        'unknown' => getJson('/api/v1/invites/'.str_repeat('a', 64)),
        'malformed' => getJson('/api/v1/invites/not-a-token'),
        'expired' => getJson("/api/v1/invites/{$expired}"),
        'revoked' => getJson("/api/v1/invites/{$revoked}"),
        'accepted' => getJson("/api/v1/invites/{$accepted}"),
        'archived' => getJson("/api/v1/invites/{$archived}"),
    ];

    // The whole status map in ONE assertion, keyed by reason, rather than a foreach of
    // assertNotFound(). Two measured reasons. TestResponse::assertNotFound() takes NO arguments
    // (it is a thin wrapper around assertStatus(404)), and PHP silently ignores extra arguments
    // to a user-defined method - so a "{$reason} must answer 404" message passed to it never
    // reaches the output, and a failing run says only "Expected response status code [404] but
    // received 200." without naming which of the six cases it was. And comparing the whole map
    // catches a case silently DROPPED from the list above, which `toHaveCount(6)` plus a foreach
    // also did, but now in the same assertion that names the statuses.
    $statuses = array_map(fn (TestResponse $r): int => $r->getStatusCode(), $responses);

    expect($statuses)->toBe([
        'unknown' => 404,
        'malformed' => 404,
        'expired' => 404,
        'revoked' => 404,
        'accepted' => 404,
        'archived' => 404,
    ]);

    // BYTE-identical, not merely equal JSON. Two 404 bodies would differ in whitespace alone if
    // one of these cases reached Laravel's own handler instead of the render() closure: a
    // router miss, a ModelNotFoundException or AuthorizationException::withStatus(404) all
    // render through Handler::prepareJsonResponse(), which encodes with
    // JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES, while a render() closure's JsonResponse is
    // compact. assertExactJson would pass for both.
    $bodies = array_map(fn (TestResponse $r): string => (string) $r->getContent(), $responses);

    expect(array_unique($bodies))->toHaveCount(1);
    expect($bodies['unknown'])
        ->toBe('{"message":"This invite link is no longer valid. Ask whoever invited you for a new one."}');

    // And the still-good token is not caught by any of it, so the test is not passing because
    // everything 404s.
    getJson("/api/v1/invites/{$valid}")->assertOk();
});

it('rate limits invite lookups to sixty an hour and answers with four headers', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [, $token] = invited($plan, $owner, MemberRole::Member);

    // Exhausted inside ONE it() block, deliberately: splitting setup and assertion across two
    // blocks silently resets the budget, because the array cache store does not cross the
    // per-test application rebuild.
    for ($i = 1; $i <= 60; $i++) {
        getJson("/api/v1/invites/{$token}")->assertOk();
    }

    $response = getJson("/api/v1/invites/{$token}")->assertStatus(429);

    // FOUR headers, not three. X-RateLimit-Reset is set inside the same
    // `if (! is_null($retryAfter))` block as Retry-After in ThrottleRequests::getHeaders(), so
    // the two cannot be separated.
    $rateLimitHeaders = array_values(array_filter(
        array_keys($response->headers->all()),
        fn (string $name): bool => $name === 'retry-after' || str_starts_with($name, 'x-ratelimit-'),
    ));
    sort($rateLimitHeaders);

    expect($rateLimitHeaders)->toBe([
        'retry-after', 'x-ratelimit-limit', 'x-ratelimit-remaining', 'x-ratelimit-reset',
    ]);

    $response->assertHeader('X-RateLimit-Limit', '60')
        ->assertHeader('X-RateLimit-Remaining', '0');

    // Retry-After and X-RateLimit-Reset are asserted for presence and shape, not for a value:
    // both are computed from the clock at the moment the window opened, so pinning 3600 would
    // make this test fail whenever the sixty requests above happen to straddle a second
    // boundary. The header SET above is the claim; these two are the shape.
    expect($response->headers->get('Retry-After'))->toMatch('/^[0-9]+$/');
    expect($response->headers->get('X-RateLimit-Reset'))->toMatch('/^[0-9]+$/');

    expect($response->json('message'))->toBe('Too Many Attempts.');
});

it('keys the invite-lookup limit on the IP and not on a shared bucket', function (): void {
    // T25-1: the test above this one (and the comment above the 'invite-lookup' limiter) pin
    // only the COUNT (sixty) and the header set. Every request in that test, and every other
    // request in this file, arrives with the test client's default REMOTE_ADDR, so nothing
    // above distinguishes a per-IP key from a constant one - the suite would stay green if
    // ->by($request->ip() ?? 'unknown') were replaced with ->by('constant'). This test exists
    // to make that distinction observable, the way CreatePlanTest's own per-user limiter test
    // distinguishes per-user from constant.
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    [, $token] = invited($plan, $owner, MemberRole::Member);

    // The key SHAPE, pinned the way CreatePlanTest pins md5('plans-create'.$userId):
    // ThrottleRequests hashes $limiterName.$limit->key unconditionally
    // (self::$shouldHashKeys = true), so one request from a known IP writes exactly to
    // md5('invite-lookup'.$ip), with no further transformation - measured against the real
    // ArrayStore CACHE_STORE=array this suite pins, which applies no prefix of its own. Value
    // 1, not merely present: RateLimiter::hit() begins at 0 and increments once per call.
    withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->getJson("/api/v1/invites/{$token}")->assertOk();

    expect(Cache::get(md5('invite-lookup'.'203.0.113.10')))
        ->toBe(1, 'one request from this IP must hit exactly this key, once');

    // Exhaust that same IP's budget (one request already spent above).
    for ($i = 2; $i <= 60; $i++) {
        withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
            ->getJson("/api/v1/invites/{$token}")->assertOk();
    }

    withServerVariables(['REMOTE_ADDR' => '203.0.113.10'])
        ->getJson("/api/v1/invites/{$token}")->assertStatus(429);

    // The property this test exists to prove: a DIFFERENT caller's budget is untouched by the
    // first's exhaustion. Under a key that had stopped varying by IP - a constant string, a
    // dropped ->by() clause - this request would share the first caller's now-exhausted bucket
    // and 429 too.
    withServerVariables(['REMOTE_ADDR' => '203.0.113.20'])
        ->getJson("/api/v1/invites/{$token}")->assertOk();
});

it('does not report an unusable invite link as an application error', function (): void {
    // Expected control flow, not a bug: every stale, revoked or mistyped invite link reaches
    // this at a rate that scales with traffic. Left reportable, each one would be logged at
    // ERROR on the same channel the security warnings use.
    //
    // report() is exercised directly rather than through an HTTP round trip, because the
    // behaviour under test is bootstrap/app.php's dontReport() list and the container-bound
    // ExceptionHandler is what reads it.
    $logs = captureLogs();

    app(ExceptionHandler::class)->report(new InviteNotUsable);

    expect(capturedLogRecords($logs))->toBe([]);

    // The second half, because a dontReport() list is exactly the kind of change that silences
    // more than intended.
    app(ExceptionHandler::class)->report(new RuntimeException('a genuinely unexpected failure'));

    $unexpected = capturedLogRecords($logs);

    expect($unexpected)->toHaveCount(1);
    expect($unexpected[0]['level'])->toBe('ERROR');
});
