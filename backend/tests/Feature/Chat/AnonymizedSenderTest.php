<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;
use function Pest\Laravel\patchJson;
use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/**
 * What the chat surface looks like once an author has deleted their account (ADR 0012).
 *
 * tests/Feature/Me/DeleteAccountTest.php's own "anonymizes the departing user's chat messages"
 * case pins the database write. This file pins what a REMAINING member sees and may do
 * afterwards, which is the half a column-level assertion cannot reach: the message is still in
 * the list, both author fields are null, and the author-only edit and delete gates refuse
 * everybody rather than letting the next caller inherit an ownerless message.
 */
it('keeps an anonymized message in the list with a null author and no display name', function (): void {
    $plan = Plan::factory()->create();
    $leaver = activeMemberOf($plan);
    $stayer = activeMemberOf($plan);

    postJson("/api/v1/plans/{$plan->id}/messages", ['body' => 'I booked the table'], [
        'Authorization' => 'Bearer '.$leaver->createToken('t')->plainTextToken,
    ])->assertCreated();

    deleteJson('/api/v1/me', [], [
        'Authorization' => 'Bearer '.$leaver->createToken('t')->plainTextToken,
    ])->assertNoContent();

    // Mandatory before a second user's request: Sanctum's guard caches the resolved user for
    // the whole test, so without this the read below authenticates as the (now deleted) leaver
    // - see CreatePlanTest's own use of this helper for the measurement.
    forgetAuthenticatedUser();

    $list = getJson("/api/v1/plans/{$plan->id}/messages", [
        'Authorization' => 'Bearer '.$stayer->createToken('t')->plainTextToken,
    ])->assertOk();

    expect($list->json('data.*.body'))->toBe(['I booked the table']);
    // Both nulls, and both for the same one reason: there is no author left to name. user_id
    // null is also what makes user_display_name null - the repository never looks a name up
    // for a null author id.
    expect($list->json('data.0.user_id'))->toBeNull();
    expect($list->json('data.0.user_display_name'))->toBeNull();
});

it('refuses every member’s edit and delete of an anonymized message, not just a non-author’s', function (): void {
    // The author-only gate in EditMessage::execute() and DeleteMessage::execute() is
    // `$message->userId !== $actor->getAuthIdentifier()`, which refuses a null userId for
    // everybody because null is never equal to a real uuid string. That is the correct
    // behaviour and it is one `!==` away from the opposite, so it is asserted rather than
    // reasoned about: a message nobody owns is a message nobody may rewrite.
    $plan = Plan::factory()->create();
    $stayer = activeMemberOf($plan);
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = null;
    $message->body = 'written by someone who has since left';
    $message->save();

    $headers = ['Authorization' => 'Bearer '.$stayer->createToken('t')->plainTextToken];

    patchJson("/api/v1/plans/{$plan->id}/messages/{$message->id}", ['body' => 'hijack'], $headers)
        ->assertStatus(403);

    deleteJson("/api/v1/plans/{$plan->id}/messages/{$message->id}", [], $headers)
        ->assertStatus(403);

    expect($message->refresh()->body)->toBe('written by someone who has since left');
    expect($message->refresh()->deleted_at)->toBeNull();
});
