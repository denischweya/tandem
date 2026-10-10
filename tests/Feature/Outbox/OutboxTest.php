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
    app(OutboxHandlers::class)->override('user.registered', function (array $payload) use (&$seen): void {
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
    app(OutboxHandlers::class)->override('user.registered', function () use (&$calls): void {
        $calls++;
    });

    app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000001', 'user.registered', []);

    app(OutboxDispatcher::class)->dispatchPending();
    app(OutboxDispatcher::class)->dispatchPending();

    expect($calls)->toBe(1);
});

it('records a failing handler as an attempt and leaves the event pending', function (): void {
    app(OutboxHandlers::class)->override('user.registered', function (): void {
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
    app(OutboxHandlers::class)->override('user.registered', function (array $payload) use (&$order): void {
        $order[] = $payload['n'];
    });

    foreach ([3, 1, 2] as $n) {
        $event = app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-00000000000'.$n, 'user.registered', ['n' => $n]);
        $event->forceFill(['occurred_at' => now()->addMinutes($n)])->save();
    }

    app(OutboxDispatcher::class)->dispatchPending(limit: 2);

    expect($order)->toBe([1, 2]);
});

it('stops claiming a row that has failed too many times, so it cannot wedge the queue', function (): void {
    // The failure mode this guards: claim() takes the first $limit unpublished rows in
    // occurred_at order, so a hundred permanently-failing rows at the HEAD meant no
    // verification email and no password-reset email was ever sent again - with
    // outbox:dispatch exiting SUCCESS every minute and `attempts` incremented by every pass
    // and read by nothing. A concrete producer exists: after an APP_KEY rotation the
    // password.reset_requested handler's Crypt::decryptString throws for ever.
    app(OutboxHandlers::class)->override('user.registered', function (array $payload): void {
        if (($payload['poison'] ?? false) === true) {
            throw new RuntimeException('undecryptable for ever');
        }
    });

    // A poison row ahead of a healthy one, by occurred_at - the position a systematically
    // failing row occupies in every future pass, not an unlucky one.
    $poison = app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000001', 'user.registered', ['poison' => true]);
    $poison->forceFill(['occurred_at' => now()->subHour(), 'attempts' => OutboxDispatcher::MAX_ATTEMPTS])->save();

    $healthy = app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000002', 'user.registered', []);

    $summary = app(OutboxDispatcher::class)->dispatchPending(limit: 1);

    // limit: 1 is the whole test. With the poison row still claimable it fills the window and
    // the healthy row behind it is never reached.
    expect($summary->published)->toBe(1);
    expect($summary->failed)->toBe(0);
    expect($summary->abandoned)->toBe(1);

    expect($healthy->refresh()->published_at)->not->toBeNull();
    // Skipped, not lost: still unpublished, still there, recoverable by resetting attempts.
    expect($poison->refresh()->published_at)->toBeNull();
});

it('records a row crossing the attempt threshold exactly once', function (): void {
    app(OutboxHandlers::class)->override('user.registered', function (): void {
        throw new RuntimeException('smtp is down');
    });

    $event = app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000001', 'user.registered', []);
    $event->forceFill(['attempts' => OutboxDispatcher::MAX_ATTEMPTS - 1])->save();

    $logs = captureLogs();

    // The pass that takes it to MAX_ATTEMPTS logs...
    app(OutboxDispatcher::class)->dispatchPending();
    // ...and every pass after it finds the row unclaimable and says nothing more. 1440
    // identical lines a day is how an alert gets muted.
    app(OutboxDispatcher::class)->dispatchPending();
    app(OutboxDispatcher::class)->dispatchPending();

    $abandoned = array_values(array_filter(
        capturedLogRecords($logs),
        fn (array $record): bool => $record['message'] === 'outbox.event_abandoned',
    ));

    expect($abandoned)->toHaveCount(1);
    expect($abandoned[0]['level'])->toBe('WARNING');
    expect($abandoned[0]['context']['outbox_event_id'] ?? null)->toBe($event->id);
    expect($abandoned[0]['context']['event_type'] ?? null)->toBe('user.registered');
    expect($abandoned[0]['context']['attempts'] ?? null)->toBe(OutboxDispatcher::MAX_ATTEMPTS);
    // No payload: it can carry an encrypted password-reset token.
    expect(array_keys($abandoned[0]['context']))
        ->toEqualCanonicalizing(['event', 'outbox_event_id', 'event_type', 'attempts', 'last_error']);
});

it('keeps retrying a row that has not reached the threshold', function (): void {
    // The converse, so a threshold set to 1 - which would abandon every transient failure
    // immediately - fails too. A restart, a connection reset or a brief SMTP outage must be
    // retried, which is the whole reason outbox:dispatch exits SUCCESS.
    $calls = 0;
    app(OutboxHandlers::class)->override('user.registered', function () use (&$calls): void {
        $calls++;
        throw new RuntimeException('smtp is down');
    });

    app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000001', 'user.registered', []);

    foreach (range(1, 3) as $ignored) {
        app(OutboxDispatcher::class)->dispatchPending();
    }

    expect($calls)->toBe(3);
    expect(OutboxEvent::sole()->attempts)->toBe(3);
});

it('reports the abandoned backlog on the command line, separately from this pass failures', function (): void {
    app(OutboxHandlers::class)->override('user.registered', function (): void {
        throw new RuntimeException('smtp is down');
    });

    $stuck = app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000001', 'user.registered', []);
    $stuck->forceFill(['attempts' => OutboxDispatcher::MAX_ATTEMPTS])->save();

    app(OutboxWriter::class)->write('User', '018f0000-0000-7000-8000-000000000002', 'user.registered', []);

    // "failed" self-heals on the next pass, which is why this command exits SUCCESS;
    // "abandoned" never does. Two numbers, because they mean opposite things.
    runArtisan('outbox:dispatch')
        ->expectsOutputToContain('"published":0,"failed":1,"abandoned":1')
        ->assertSuccessful();
});

it('refuses a second handler for the same event type instead of replacing it silently', function (): void {
    // AppServiceProvider's three registration sites are independent calls on one shared
    // singleton. Registering the no-op user.deleted closure under 'user.registered' by mistake
    // would have deleted every verification email in the application, with the suite green and
    // the dispatcher marking events published - which is why that hazard can no longer live in
    // a comment at one of the three sites.
    $handlers = app(OutboxHandlers::class);

    expect(fn () => $handlers->register('user.registered', function (): void {}))
        ->toThrow(LogicException::class, 'already registered for event type [user.registered]');
});

it('lets a deliberate replacement through override(), which is how a test swaps in a probe', function (): void {
    $handlers = app(OutboxHandlers::class);

    $handlers->override('user.registered', function (): void {});
    $handlers->override('user.registered', function (): void {});

    expect($handlers->handlerFor('user.registered'))->not->toBeNull();
});
