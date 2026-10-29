<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('sends a message as an active member and returns it', function (): void {
    $plan = Plan::factory()->create();
    $user = activeMemberOf($plan);

    postJson("/api/v1/plans/{$plan->id}/messages", ['body' => 'Should we do Italian?'], [
        'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
    ])
        ->assertCreated()
        ->assertJsonPath('data.body', 'Should we do Italian?')
        ->assertJsonPath('data.user_id', $user->id)
        ->assertJsonPath('data.reactions', []);
});

it('rejects a non-member with the same 404 every Plan sub-resource uses', function (): void {
    $plan = Plan::factory()->create();
    $outsider = User::factory()->hasProfile()->create();

    postJson("/api/v1/plans/{$plan->id}/messages", ['body' => 'hi'], [
        'Authorization' => 'Bearer '.$outsider->createToken('t')->plainTextToken,
    ])->assertStatus(404);
});

it('rejects an empty body', function (): void {
    $plan = Plan::factory()->create();
    $user = activeMemberOf($plan);

    postJson("/api/v1/plans/{$plan->id}/messages", ['body' => '   '], [
        'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
    ])->assertStatus(422)->assertJsonValidationErrorFor('body');
});
