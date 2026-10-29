<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\PlanMessage;
use App\Models\PlanMessageReaction;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

it('creates plan_messages with the columns this slice needs', function (): void {
    expect(Schema::hasColumns('plan_messages', [
        'id', 'plan_id', 'user_id', 'body', 'edited_at', 'deleted_at', 'created_at', 'updated_at',
    ]))->toBeTrue();
});

it('creates plan_message_reactions with a unique constraint on message, user and reaction', function (): void {
    expect(Schema::hasColumns('plan_message_reactions', [
        'id', 'message_id', 'user_id', 'reaction', 'created_at',
    ]))->toBeTrue();

    $plan = Plan::factory()->create();
    $user = User::factory()->hasProfile()->create();
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $user->id;
    $message->body = 'hi';
    $message->save();

    $makeReaction = fn () => PlanMessageReaction::query()->insert([
        'id' => (string) Str::uuid7(),
        'message_id' => $message->id,
        'user_id' => $user->id,
        'reaction' => '👍',
        'created_at' => now(),
    ]);

    $makeReaction();

    expect($makeReaction)->toThrow(QueryException::class);
});
