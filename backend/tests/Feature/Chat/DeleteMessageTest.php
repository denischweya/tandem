<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\deleteJson;
use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('lets the author soft-delete their own message, and it drops out of the list', function (): void {
    $plan = Plan::factory()->create();
    $author = activeMemberOf($plan);
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $author->id;
    $message->body = 'gone soon';
    $message->save();

    deleteJson("/api/v1/plans/{$plan->id}/messages/{$message->id}", [], [
        'Authorization' => 'Bearer '.$author->createToken('t')->plainTextToken,
    ])->assertNoContent();

    $list = getJson("/api/v1/plans/{$plan->id}/messages", [
        'Authorization' => 'Bearer '.$author->createToken('t')->plainTextToken,
    ])->assertOk();

    expect($list->json('data.*.id'))->not->toContain($message->id);
});
