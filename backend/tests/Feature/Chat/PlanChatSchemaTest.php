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

it('lets plan_messages.user_id be null, and nulls it when the author row is hard-deleted', function (): void {
    // ADR 0012. The shipped column was NOT NULL with a RESTRICT foreign key, which made the
    // anonymisation the chat design document asks for impossible to write and made the day-30
    // hard purge (PurgeDeletedUsersCommand) fail for ever for any user who had sent one
    // message. Both halves are asserted here rather than reasoned about: the nullable write is
    // what DeleteAccount performs, and forceDelete() is the backstop the FK's ON DELETE SET
    // NULL provides for a user whose rows that transaction somehow missed.
    $plan = Plan::factory()->create();
    $user = User::factory()->hasProfile()->create();
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $user->id;
    $message->body = 'still worth reading';
    $message->save();

    $message->user_id = null;
    $message->save();

    expect($message->refresh()->user_id)->toBeNull();

    $message->user_id = $user->id;
    $message->save();

    $user->forceDelete();

    // The row survives the author's hard deletion with its text intact - the whole point of
    // not making this column CASCADE.
    expect($message->refresh()->user_id)->toBeNull();
    expect($message->refresh()->body)->toBe('still worth reading');
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
