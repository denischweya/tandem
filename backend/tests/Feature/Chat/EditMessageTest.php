<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\patchJson;

uses(RefreshDatabase::class);

it('lets the author edit their own message', function (): void {
    $plan = Plan::factory()->create();
    $author = activeMemberOf($plan);
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $author->id;
    $message->body = 'original';
    $message->save();

    patchJson("/api/v1/plans/{$plan->id}/messages/{$message->id}", ['body' => 'edited'], [
        'Authorization' => 'Bearer '.$author->createToken('t')->plainTextToken,
    ])
        ->assertOk()
        ->assertJsonPath('data.body', 'edited');

    expect($message->refresh()->edited_at)->not->toBeNull();
});

it('refuses another member editing someone else’s message', function (): void {
    $plan = Plan::factory()->create();
    $author = activeMemberOf($plan);
    $other = activeMemberOf($plan);
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $author->id;
    $message->body = 'original';
    $message->save();

    patchJson("/api/v1/plans/{$plan->id}/messages/{$message->id}", ['body' => 'hijack'], [
        'Authorization' => 'Bearer '.$other->createToken('t')->plainTextToken,
    ])->assertStatus(403);
});
