<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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
