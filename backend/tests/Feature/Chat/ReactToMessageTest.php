<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanMessage;
use App\Models\PlanMessageReaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\assertDatabaseCount;
use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('reacts to a message and sees the reaction summarised on it', function (): void {
    $plan = Plan::factory()->create();
    $author = activeMemberOf($plan);
    $reactor = activeMemberOf($plan);
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $author->id;
    $message->body = 'Should we do Italian?';
    $message->save();

    postJson("/api/v1/plans/{$plan->id}/messages/{$message->id}/reactions", ['reaction' => '👍'], [
        'Authorization' => 'Bearer '.$reactor->createToken('t')->plainTextToken,
    ])->assertNoContent();

    $list = getJson("/api/v1/plans/{$plan->id}/messages", [
        'Authorization' => 'Bearer '.$reactor->createToken('t')->plainTextToken,
    ])->assertOk();

    expect($list->json('data.0.reactions'))->toBe([
        ['reaction' => '👍', 'count' => 1, 'reacted_by_viewer' => true],
    ]);
});

it('counts two members’ identical reaction once each, and scopes reacted_by_viewer to the reader', function (): void {
    // Every other case in this file has ONE reactor and ONE emoji, which is the one shape that
    // cannot see either half of reactionsByMessageId()'s aggregation: a `count` hardcoded to 1
    // passes it, and so does a reacted_by_viewer hardcoded to true. Two reactors and a third
    // reader who reacted to nothing is the smallest fixture that distinguishes all three.
    $plan = Plan::factory()->create();
    $author = activeMemberOf($plan);
    $secondReactor = activeMemberOf($plan);
    $bystander = activeMemberOf($plan);
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $author->id;
    $message->body = 'Should we do Italian?';
    $message->save();

    postJson("/api/v1/plans/{$plan->id}/messages/{$message->id}/reactions", ['reaction' => '👍'], [
        'Authorization' => 'Bearer '.$author->createToken('t')->plainTextToken,
    ])->assertNoContent();

    // forgetAuthenticatedUser() before every change of actor: Sanctum's guard memoises the
    // resolved user for the whole test, so without it the second POST below authenticates as
    // $author, the UNIQUE(message_id, user_id, reaction) constraint swallows it as a duplicate,
    // and this test would assert count: 2 against a single row - see CreatePlanTest's own use of
    // this helper for the measurement.
    forgetAuthenticatedUser();

    postJson("/api/v1/plans/{$plan->id}/messages/{$message->id}/reactions", ['reaction' => '👍'], [
        'Authorization' => 'Bearer '.$secondReactor->createToken('t')->plainTextToken,
    ])->assertNoContent();

    // Read by one of the two reactors: one group, two reactions in it, and the viewer is in it.
    $asReactor = getJson("/api/v1/plans/{$plan->id}/messages", [
        'Authorization' => 'Bearer '.$secondReactor->createToken('t')->plainTextToken,
    ])->assertOk();

    expect($asReactor->json('data.0.reactions'))->toBe([
        ['reaction' => '👍', 'count' => 2, 'reacted_by_viewer' => true],
    ]);

    forgetAuthenticatedUser();

    // ...and read by a member who reacted to nothing: the same count, the other flag. count is
    // a property of the message and reacted_by_viewer is a property of the request, which is
    // exactly the distinction a single-reactor fixture cannot make.
    $asBystander = getJson("/api/v1/plans/{$plan->id}/messages", [
        'Authorization' => 'Bearer '.$bystander->createToken('t')->plainTextToken,
    ])->assertOk();

    expect($asBystander->json('data.0.reactions'))->toBe([
        ['reaction' => '👍', 'count' => 2, 'reacted_by_viewer' => false],
    ]);
});

it('re-adding the same reaction is idempotent, writing no second row', function (): void {
    // The UniqueConstraintViolationException catch in EloquentPlanMessageRepository::
    // addReaction() - whose own docblock records that losing that race IS success - was
    // exercised by nothing. "It does not crash" is not the assertion that matters here: the row
    // count is, because a catch that swallowed the error while something else had already
    // written a duplicate would still answer 204 twice.
    $plan = Plan::factory()->create();
    $author = activeMemberOf($plan);
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $author->id;
    $message->body = 'hi';
    $message->save();

    $headers = ['Authorization' => 'Bearer '.$author->createToken('t')->plainTextToken];

    postJson("/api/v1/plans/{$plan->id}/messages/{$message->id}/reactions", ['reaction' => '🎉'], $headers)
        ->assertNoContent();
    postJson("/api/v1/plans/{$plan->id}/messages/{$message->id}/reactions", ['reaction' => '🎉'], $headers)
        ->assertNoContent();

    assertDatabaseCount('plan_message_reactions', 1);

    $list = getJson("/api/v1/plans/{$plan->id}/messages", $headers)->assertOk();

    expect($list->json('data.0.reactions'))->toBe([
        ['reaction' => '🎉', 'count' => 1, 'reacted_by_viewer' => true],
    ]);

    // The connection is still usable after the swallowed violation, which is the other half of
    // that docblock's measurement: under RefreshDatabase's enclosing transaction, a save() that
    // violates a constraint outside its own DB::transaction() leaves Postgres in 25P02 and
    // fails the NEXT query of any kind. The GET above already proves this, and so does this
    // read - stated so a future refactor that removes the inner transaction fails here with the
    // reason written down beside it.
    expect(PlanMessageReaction::query()->count())->toBe(1);
});

it('removing a reaction drops it from the summary', function (): void {
    $plan = Plan::factory()->create();
    $author = activeMemberOf($plan);
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $author->id;
    $message->body = 'hi';
    $message->save();

    $headers = ['Authorization' => 'Bearer '.$author->createToken('t')->plainTextToken];

    postJson("/api/v1/plans/{$plan->id}/messages/{$message->id}/reactions", ['reaction' => '❤️'], $headers)
        ->assertNoContent();

    // rawurlencode(), not the raw emoji spliced into the path: any real HTTP client
    // percent-encodes a non-ASCII path segment before putting it on the wire, and
    // Laravel/Symfony decode it back before binding {reaction} - measured separately. A raw,
    // un-encoded multi-byte character spliced directly into a test URI instead hits PHP
    // 8.4's own parse_url() (called by Symfony\Component\HttpFoundation\Request::create(),
    // which every Pest HTTP helper routes through), which treats bytes in the 0x80-0x9F range
    // as suspect even when they are UTF-8 continuation bytes, not control characters, and
    // replaces them with `_` - corrupting the path into invalid UTF-8 and failing
    // Illuminate\Http\Middleware\ValidatePathEncoding with a 400 before routing ever runs.
    // That is a property of how this ONE test builds its request, not of the application:
    // percent-encoded, which is the only wire-valid form, round-trips correctly end to end
    // (confirmed separately against RemoveReactionController).
    $reaction = rawurlencode('❤️');
    deleteJson("/api/v1/plans/{$plan->id}/messages/{$message->id}/reactions/{$reaction}", [], $headers)
        ->assertNoContent();

    $list = getJson("/api/v1/plans/{$plan->id}/messages", $headers)->assertOk();

    expect($list->json('data.0.reactions'))->toBe([]);
});

it('rejects a reaction outside the five-emoji set', function (): void {
    $plan = Plan::factory()->create();
    $author = activeMemberOf($plan);
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $author->id;
    $message->body = 'hi';
    $message->save();

    postJson("/api/v1/plans/{$plan->id}/messages/{$message->id}/reactions", ['reaction' => '🤠'], [
        'Authorization' => 'Bearer '.$author->createToken('t')->plainTextToken,
    ])->assertStatus(422)->assertJsonValidationErrorFor('reaction');
});
