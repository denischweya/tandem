<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\PlanMessage;
use App\Models\User;
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

it('publishes a null display name for a sender who has no profile row, keeping the user_id', function (): void {
    // The THIRD state PlanMessageSummary::$userDisplayName's docblock names, and the one that is
    // neither "named" nor "anonymised": a live user_id whose user_profiles row is absent.
    //
    // user_profiles.user_id is UNIQUE NOT NULL with CASCADE and registration creates both rows,
    // so this is defensive rather than a shape production produces today - which is exactly why
    // it is pinned here. MEASURED: PHPStan at level 8 does NOT flag dropping the `?? null` in
    // EloquentPlanMessageRepository::listForPlan() (errors: 0, because displayNamesByUserId() is
    // declared array<string, string> and an offset on one is a string, not a possible null), so
    // the analyser holds nothing open - this case is the only force that does. Without the
    // guard, PHP 8.4's own undefined-array-key E_WARNING is promoted to an ErrorException by
    // Illuminate\Foundation\Bootstrap\HandleExceptions and this read becomes a 500. The same
    // attribution EloquentPlanActivityRepository's equivalent comment records for actor names,
    // re-measured here rather than transcribed.
    $plan = Plan::factory()->create();
    $reader = activeMemberOf($plan);

    // No ->hasProfile(): this is the one factory call in the chat suite that deliberately omits
    // it, and the member row is written by hand because activeMemberOf() always makes a profile.
    $profileless = User::factory()->create();
    $member = new PlanMember;
    $member->id = (string) Str::uuid7();
    $member->plan_id = $plan->id;
    $member->user_id = $profileless->id;
    $member->role = 'member';
    $member->status = 'active';
    $member->joined_at = now();
    $member->save();

    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $profileless->id;
    $message->body = 'from someone with no profile row';
    $message->save();

    $response = getJson("/api/v1/plans/{$plan->id}/messages", [
        'Authorization' => 'Bearer '.$reader->createToken('t')->plainTextToken,
    ])->assertOk();

    // The id survives and only the name is missing, which is what makes this state different
    // from an anonymised author - where both are null.
    expect($response->json('data.0.user_id'))->toBe($profileless->id);
    expect($response->json('data.0.user_display_name'))->toBeNull();
});
