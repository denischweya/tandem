<?php

declare(strict_types=1);

use Illuminate\Console\Scheduling\Event;
use Illuminate\Console\Scheduling\Schedule;

/**
 * routes/console.php is production code with no other coverage, and what it gets wrong is
 * invisible by construction: a scheduled command's exit code is read by nothing, and its
 * output goes to /dev/null unless the schedule says otherwise. So the two commands' different
 * failure contracts - outbox:dispatch exits SUCCESS on a failed event because the retry IS
 * the fix; users:purge-deleted exits FAILURE because nothing retries it - only mean anything
 * if the second one's failure is routed somewhere a human can reach.
 */
function scheduledEvent(string $command): Event
{
    foreach (app(Schedule::class)->events() as $event) {
        if (is_string($event->command) && str_contains($event->command, $command)) {
            return $event;
        }
    }

    throw new RuntimeException("No scheduled event runs [{$command}].");
}

it('captures the purge run output instead of discarding it', function (): void {
    // Illuminate\Console\Scheduling\Event::$output defaults to '/dev/null', so every
    // ->components->warn() the command writes per stuck user is thrown away unless something
    // calls ensureOutputIsBeingCaptured() - which onFailureWithOutput() does.
    expect(scheduledEvent('users:purge-deleted')->output)->not->toBe('/dev/null');
});

it('logs when the purge run exits non-zero', function (): void {
    $logs = captureLogs();

    $event = scheduledEvent('users:purge-deleted');
    $event->exitCode = 1;
    $event->callAfterCallbacks(app());

    expect(capturedLogRecords($logs))->toContain([
        'level' => 'ERROR',
        'message' => 'users.purge_deleted.run_failed',
        'context' => ['event' => 'users.purge_deleted.run_failed', 'output' => ''],
    ]);
});

it('stays silent when the purge run succeeds', function (): void {
    $logs = captureLogs();

    $event = scheduledEvent('users:purge-deleted');
    $event->exitCode = 0;
    $event->callAfterCallbacks(app());

    // Without this the test above would pass against an unconditional ->then() hook, which
    // would alarm every night at 03:00 and be muted inside a week.
    expect(capturedLogRecords($logs))->toBe([]);
});

it('leaves outbox:dispatch without a failure hook, deliberately', function (): void {
    $logs = captureLogs();

    // DispatchOutboxCommand returns SUCCESS even when handlers fail, because a pending event
    // is retried on the next pass - so a failure hook here could never fire, and adding one
    // would read as though it could. The condition that genuinely needs attention is a row
    // that has stopped being retried, which OutboxDispatcher reports itself.
    $event = scheduledEvent('outbox:dispatch');
    $event->exitCode = 1;
    $event->callAfterCallbacks(app());

    expect($event->output)->toBe('/dev/null');
    expect(capturedLogRecords($logs))->toBe([]);
});
