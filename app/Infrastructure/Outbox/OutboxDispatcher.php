<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Domain\Shared\Clock;
use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;
use Throwable;

final readonly class OutboxDispatcher
{
    /**
     * After this many failed attempts a row is no longer claimed.
     *
     * The bound exists because the claim window is the first `$limit` unpublished rows in
     * occurred_at order, and `attempts` was incremented by every failure and then read by
     * nothing. So one hundred permanently-failing rows at the head of the queue meant no
     * verification email and no password-reset email was ever sent again, for ever, while
     * outbox:dispatch went on exiting SUCCESS (deliberately - see DispatchOutboxCommand) and
     * the scheduler reported a healthy minute every minute.
     *
     * That is not hypothetical. There is a concrete producer: AppServiceProvider's
     * password.reset_requested handler calls Crypt::decryptString on the encrypted token, and
     * after an APP_KEY rotation every outstanding row throws DecryptException - deliberately
     * uncaught, because a retry IS the right answer to a transient failure. It is never the
     * right answer to that one.
     *
     * Twenty, because the schedule runs this every minute: twenty minutes of unbroken failure
     * is longer than a restart, a connection reset or a brief SMTP outage, and far shorter
     * than the time it would take anyone to notice an empty mailbox.
     *
     * A skipped row is NOT a lost row. published_at stays NULL and nothing is deleted, so once
     * the underlying problem is fixed an operator resets `attempts` and the row flows again -
     * which is the whole reason this is a claim filter rather than a terminal state column
     * (the migration freeze would forbid the column in any case).
     */
    public const MAX_ATTEMPTS = 20;

    public function __construct(
        private OutboxHandlers $handlers,
        private Clock $clock,
    ) {}

    public function dispatchPending(int $limit = 100): DispatchSummary
    {
        $published = 0;
        $failed = 0;

        foreach ($this->claim($limit) as $event) {
            $handler = $this->handlers->handlerFor($event->event_type);

            if ($handler === null) {
                $this->recordFailure($event, "No handler registered for event type [{$event->event_type}].");
                $failed++;

                continue;
            }

            try {
                $handler($event->payload);
                $event->forceFill(['published_at' => $this->clock->now(), 'last_error' => null])->save();
                $published++;
            } catch (Throwable $e) {
                // The message, not the trace: a trace can carry a DSN or a credential, and
                // last_error is read by anyone with database access.
                $this->recordFailure($event, $e->getMessage());
                $failed++;
            }
        }

        // Reported on every pass, so the backlog is visible in the command's own output line
        // without a log entry per minute. The log entry happens once, at the moment a row
        // crosses the threshold - see recordFailure().
        return new DispatchSummary($published, $failed, $this->abandonedCount());
    }

    /**
     * A plain ordered, limited query - no FOR UPDATE, no transaction. There is no row lock
     * to take here: this dispatcher's concurrency safety comes from the schedule registering
     * `outbox:dispatch` with ->withoutOverlapping() (routes/console.php), so exactly one
     * dispatcher process is ever claiming rows at a time. That is sufficient today because
     * the only caller is that single scheduled command.
     *
     * It would not be sufficient for genuinely concurrent dispatchers (e.g. several queue
     * workers calling dispatchPending() at once): two of them could both select the same
     * unpublished rows before either writes published_at, and hand the same event to a
     * handler twice. Making that safe needs a claim column (e.g. `claimed_at`), written in
     * its own short transaction before the handler runs, so a second claimant's UPDATE sees
     * the row already taken - which needs a migration, and the Cycle 1 migration freeze
     * defers that.
     *
     * Oldest-first by occurred_at, with id as the tiebreak - UUIDv7 is time-sortable, so
     * two events written in the same microsecond still have a stable order.
     *
     * The attempts predicate is what stops a poison row from wedging the queue: see
     * MAX_ATTEMPTS for why this is head-of-line blocking rather than a slow retry.
     *
     * @return Collection<int, OutboxEvent>
     */
    private function claim(int $limit): Collection
    {
        return OutboxEvent::query()
            ->whereNull('published_at')
            ->where('attempts', '<', self::MAX_ATTEMPTS)
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function recordFailure(OutboxEvent $event, string $message): void
    {
        $attempts = $event->attempts + 1;

        $event->forceFill([
            'attempts' => $attempts,
            'last_error' => $message,
        ])->save();

        if ($attempts !== self::MAX_ATTEMPTS) {
            return;
        }

        // Once per row, at the moment it stops being claimed - not once per pass, which would
        // be 1440 identical lines a day and filtered out of anyone's alerting within a week.
        // The ongoing backlog is in DispatchSummary::$abandoned instead, which outbox:dispatch
        // prints on every run.
        //
        // The event type and the driver's own message, no payload: a payload can carry an
        // encrypted reset token, and a log is not where that belongs.
        Log::warning('outbox.event_abandoned', [
            'event' => 'outbox.event_abandoned',
            'outbox_event_id' => $event->id,
            'event_type' => $event->event_type,
            'attempts' => $attempts,
            'last_error' => $message,
        ]);
    }

    /** Unpublished rows the claim filter will no longer pick up. */
    private function abandonedCount(): int
    {
        return OutboxEvent::query()
            ->whereNull('published_at')
            ->where('attempts', '>=', self::MAX_ATTEMPTS)
            ->count();
    }
}
