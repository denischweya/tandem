<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanMessage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('sends a message as an active member and returns it', function (): void {
    $plan = Plan::factory()->create();
    $user = activeMemberOf($plan);
    $user->profile()->update(['display_name' => 'Ada Lovelace']);

    postJson("/api/v1/plans/{$plan->id}/messages", ['body' => 'Should we do Italian?'], [
        'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
    ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Should we do Italian?')
        ->assertJsonPath('data.user_id', $user->id)
        // The sender's name on the CREATE response, not only on the list: a null here would
        // read as "anonymised author" for a message its author wrote one millisecond ago, and
        // a client rendering the optimistic row would have nothing to show beside it.
        ->assertJsonPath('data.user_display_name', 'Ada Lovelace')
        ->assertJsonPath('data.reactions', []);
});

it('rejects a non-member with the same 404 every Plan sub-resource uses', function (): void {
    $plan = Plan::factory()->create();
    $outsider = User::factory()->hasProfile()->create();

    postJson("/api/v1/plans/{$plan->id}/messages", ['body' => 'hi'], [
        'Authorization' => 'Bearer '.$outsider->createToken('t')->plainTextToken,
    ])->assertStatus(404);
});

it('limits one user to thirty messages a minute and leaves a second member of the same Plan alone', function (): void {
    // CreatePlanTest's own "limits one user to thirty an hour..." case, for chat-send. One
    // it() block, deliberately, for the reason that one records: phpunit.xml pins
    // CACHE_STORE=array and the application is rebuilt per test, so limiter state does not
    // survive a test boundary - splitting the exhaustion from the assertion would silently
    // reset the budget. No RateLimiter::clear() either: the real bucket is
    // md5('chat-send'.$userId), so clearing by name clears nothing.
    $plan = Plan::factory()->create();
    $first = activeMemberOf($plan);
    $firstToken = $first->createToken('t')->plainTextToken;

    postJson("/api/v1/plans/{$plan->id}/messages", ['body' => 'message 1'], [
        'Authorization' => 'Bearer '.$firstToken,
    ])->assertCreated();

    // The real key, not merely "a 429 arrived": ThrottleRequests hashes $limiterName.$limit->key
    // unconditionally (self::$shouldHashKeys = true), so one request wrote to exactly
    // md5('chat-send'.$userId) - the same measurement CreatePlanTest makes for plans-create.
    // Value 1, because hit() begins at 0 and increments once per call.
    expect(Cache::get(md5('chat-send'.$first->id)))->toBe(1, 'one request must hit exactly this key, once');

    for ($i = 2; $i <= 30; $i++) {
        postJson("/api/v1/plans/{$plan->id}/messages", ['body' => "message {$i}"], [
            'Authorization' => 'Bearer '.$firstToken,
        ])->assertCreated();
    }

    $refused = postJson("/api/v1/plans/{$plan->id}/messages", ['body' => 'message 31'], [
        'Authorization' => 'Bearer '.$firstToken,
    ])->assertStatus(429);

    $refused->assertHeader('X-RateLimit-Limit', '30');
    $refused->assertHeader('X-RateLimit-Remaining', '0');
    // Presence only: Retry-After is availableIn(), counted from the first of the thirty
    // requests above, so an exact 60 here would be a flake rather than an assertion.
    $refused->assertHeader('Retry-After');
    $refused->assertHeader('X-RateLimit-Reset');

    expect(PlanMessage::query()->count())->toBe(30, 'the 31st request must not have written a message');

    // Mandatory. Sanctum's guard caches the resolved user for the whole test, so without this
    // the second member's request authenticates as the FIRST, hits the same bucket, and this
    // test fails for a reason that has nothing to do with the limiter's key.
    forgetAuthenticatedUser();

    $second = activeMemberOf($plan);

    // The point of the second half: every request in this suite arrives with
    // REMOTE_ADDR = 127.0.0.1, and both members are on the SAME Plan - so a closure keyed on
    // the IP, or on the {plan} route parameter, would refuse this too, and a 429 from the wrong
    // key is indistinguishable from a 429 from the right one by status alone.
    postJson("/api/v1/plans/{$plan->id}/messages", ['body' => 'a different person entirely'], [
        'Authorization' => 'Bearer '.$second->createToken('t')->plainTextToken,
    ])->assertCreated();
});

it('rejects an empty body', function (): void {
    $plan = Plan::factory()->create();
    $user = activeMemberOf($plan);

    postJson("/api/v1/plans/{$plan->id}/messages", ['body' => '   '], [
        'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
    ])->assertStatus(422)->assertJsonValidationErrorFor('body');
});
