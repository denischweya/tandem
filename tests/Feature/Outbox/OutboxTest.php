<?php

declare(strict_types=1);

use App\Infrastructure\Outbox\OutboxDispatcher;
use App\Infrastructure\Outbox\OutboxHandlers;
use App\Infrastructure\Outbox\OutboxWriter;
use App\Models\OutboxEvent;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

it('writes an unpublished event inside the caller transaction', function (): void {
    $writer = app(OutboxWriter::class);

    DB::transaction(function () use ($writer): void {
        $writer->write('User', '018f0000-0000-7000-8000-000000000001', 'user.registered', ['email' => 'a@b.test']);
    });

    $event = OutboxEvent::sole();

    expect($event->event_type)->toBe('user.registered');
    expect($event->payload)->toBe(['email' => 'a@b.test']);
    expect($event->published_at)->toBeNull();
    expect($event->attempts)->toBe(0);
});

it('rolls the event back with the transaction that wrote it', function (): void {
    $writer = app(OutboxWriter::class);

    try {
        DB::transaction(function () use ($writer): void {
            $writer->write('User', '018f0000-0000-7000-8000-000000000001', 'user.registered', []);
            throw new RuntimeException('business rule failed after the write');
        });
    } catch (RuntimeException) {
        // expected
    }

    // This is the entire point of the pattern: a committed row and an external side effect
    // cannot diverge, because the row is part of the same commit.
    expect(OutboxEvent::count())->toBe(0);
});

it('runs the registered handler and marks the event published', function (): void {
    $seen = [];
    app(OutboxHandlers::class)->register('user.registered', function (array $payload) use (&$seen): void {
        $seen[] = $payload;
    });

    app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000001', 'user.registered', ['email' => 'a@b.test']);

    $summary = app(OutboxDispatcher::class)->dispatchPending();

    expect($summary->published)->toBe(1);
    expect($summary->failed)->toBe(0);
    expect($seen)->toBe([['email' => 'a@b.test']]);
    expect(OutboxEvent::sole()->published_at)->not->toBeNull();
});

it('does not run a published event a second time', function (): void {
    $calls = 0;
    app(OutboxHandlers::class)->register('user.registered', function () use (&$calls): void {
        $calls++;
    });

    app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000001', 'user.registered', []);

    app(OutboxDispatcher::class)->dispatchPending();
    app(OutboxDispatcher::class)->dispatchPending();

    expect($calls)->toBe(1);
});

it('records a failing handler as an attempt and leaves the event pending', function (): void {
    app(OutboxHandlers::class)->register('user.registered', function (): void {
        throw new RuntimeException('smtp is down');
    });

    app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000001', 'user.registered', []);

    $summary = app(OutboxDispatcher::class)->dispatchPending();

    $event = OutboxEvent::sole();

    expect($summary->published)->toBe(0);
    expect($summary->failed)->toBe(1);
    expect($event->published_at)->toBeNull();
    expect($event->attempts)->toBe(1);
    expect($event->last_error)->toContain('smtp is down');
});

it('leaves an event with no registered handler pending and records why', function (): void {
    app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000001', 'user.unhandled', []);

    $summary = app(OutboxDispatcher::class)->dispatchPending();

    // Deliberately not "publish it anyway": an event type nobody handles is a deployment
    // mistake, and silently marking it published destroys the only evidence of it.
    expect($summary->failed)->toBe(1);
    expect(OutboxEvent::sole()->last_error)->toContain('No handler registered');
});

it('dispatches oldest-first and respects the limit', function (): void {
    $order = [];
    app(OutboxHandlers::class)->register('user.registered', function (array $payload) use (&$order): void {
        $order[] = $payload['n'];
    });

    foreach ([3, 1, 2] as $n) {
        $event = app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-00000000000'.$n, 'user.registered', ['n' => $n]);
        $event->forceFill(['occurred_at' => now()->addMinutes($n)])->save();
    }

    app(OutboxDispatcher::class)->dispatchPending(limit: 2);

    expect($order)->toBe([1, 2]);
});
