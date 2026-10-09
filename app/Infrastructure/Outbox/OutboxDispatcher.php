<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Domain\Shared\Clock;
use App\Models\OutboxEvent;
use Illuminate\Database\Eloquent\Collection;
use Throwable;

final readonly class OutboxDispatcher
{
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

        return new DispatchSummary($published, $failed);
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
     * @return Collection<int, OutboxEvent>
     */
    private function claim(int $limit): Collection
    {
        return OutboxEvent::query()
            ->whereNull('published_at')
            ->orderBy('occurred_at')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    private function recordFailure(OutboxEvent $event, string $message): void
    {
        $event->forceFill([
            'attempts' => $event->attempts + 1,
            'last_error' => $message,
        ])->save();
    }
}
