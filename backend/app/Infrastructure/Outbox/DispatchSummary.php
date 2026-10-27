<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

final readonly class DispatchSummary
{
    public function __construct(
        public int $published,
        public int $failed,
        /**
         * Unpublished rows past OutboxDispatcher::MAX_ATTEMPTS, which this pass did not claim.
         *
         * A separate number from $failed, and the distinction is the point: $failed is "tried
         * and did not work this minute", which self-heals on the next pass and is why
         * DispatchOutboxCommand exits SUCCESS. This is "no longer being tried", which does not
         * self-heal and needs a human. Printed on every run so the backlog is readable without
         * a log line per minute.
         */
        public int $abandoned = 0,
    ) {}
}
