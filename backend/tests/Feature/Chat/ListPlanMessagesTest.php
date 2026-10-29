<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

use function Pest\Laravel\getJson;

uses(RefreshDatabase::class);

it('lists a plan’s messages newest first', function (): void {
    $plan = Plan::factory()->create();
    $user = activeMemberOf($plan);

    foreach (['first', 'second', 'third'] as $i => $body) {
        $message = new PlanMessage;
        $message->id = (string) Str::uuid7();
        $message->plan_id = $plan->id;
        $message->user_id = $user->id;
        $message->body = $body;
        $message->created_at = now()->addSeconds($i);
        $message->updated_at = $message->created_at;
        $message->save();
    }

    $response = getJson("/api/v1/plans/{$plan->id}/messages", [
        'Authorization' => 'Bearer '.$user->createToken('t')->plainTextToken,
    ])->assertOk();

    expect($response->json('data.*.body'))->toBe(['third', 'second', 'first']);
});
