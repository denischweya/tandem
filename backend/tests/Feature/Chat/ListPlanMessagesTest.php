<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanMessage;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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

it('publishes each sender’s own display name, from one batched lookup', function (): void {
    // The same correctness bar EloquentPlanActivityRepository's batched actor-name lookup sets,
    // applied to messages: TWO senders, so a per-row lookup that happened to reuse the wrong
    // key - or a grouped array indexed by the wrong thing - shows up as a crossed-over name
    // rather than passing on a single-sender page. Three messages over two senders, so the
    // repeated sender has to resolve twice to the same name from one query.
    $plan = Plan::factory()->create();
    $ada = activeMemberOf($plan);
    $ada->profile()->update(['display_name' => 'Ada Lovelace']);
    $grace = activeMemberOf($plan);
    $grace->profile()->update(['display_name' => 'Grace Hopper']);

    foreach ([[$ada, 'first'], [$grace, 'second'], [$ada, 'third']] as $i => [$sender, $body]) {
        $message = new PlanMessage;
        $message->id = (string) Str::uuid7();
        $message->plan_id = $plan->id;
        $message->user_id = $sender->id;
        $message->body = $body;
        $message->created_at = now()->addSeconds($i);
        $message->updated_at = $message->created_at;
        $message->save();
    }

    $response = getJson("/api/v1/plans/{$plan->id}/messages", [
        'Authorization' => 'Bearer '.$ada->createToken('t')->plainTextToken,
    ])->assertOk();

    // Newest first, so the order is third, second, first - Ada, Grace, Ada.
    expect($response->json('data.*.user_display_name'))->toBe(['Ada Lovelace', 'Grace Hopper', 'Ada Lovelace']);
    expect($response->json('data.*.user_id'))->toBe([$ada->id, $grace->id, $ada->id]);

    // One query for the names, not one per row - the discipline the activity repository's own
    // comment records, asserted rather than described. Counted over the whole request so the
    // figure cannot be read as "the lookup ran zero times": a three-message page that issued a
    // name query per row would be three here, not one.
    $queries = [];
    DB::listen(function (QueryExecuted $query) use (&$queries): void {
        if (str_contains($query->sql, 'user_profiles')) {
            $queries[] = $query->sql;
        }
    });

    getJson("/api/v1/plans/{$plan->id}/messages", [
        'Authorization' => 'Bearer '.$ada->createToken('t')->plainTextToken,
    ])->assertOk();

    expect($queries)->toHaveCount(1, 'the display-name lookup must be batched over the page, not issued per row');
});
