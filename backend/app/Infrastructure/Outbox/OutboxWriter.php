<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use App\Domain\Shared\Clock;
use App\Models\OutboxEvent;

final readonly class OutboxWriter
{
    public function __construct(private Clock $clock) {}

    /**
     * Writes the event with no transaction of its own, deliberately: it must join the
     * caller's transaction so the row and the state change it describes commit or roll back
     * together (§85). Opening a transaction here would defeat that.
     *
     * @param  array<string, mixed>  $payload
     */
    public function write(string $aggregateType, string $aggregateId, string $eventType, array $payload): OutboxEvent
    {
        return OutboxEvent::create([
            'aggregate_type' => $aggregateType,
            'aggregate_id' => $aggregateId,
            'event_type' => $eventType,
            'payload' => $payload,
            'occurred_at' => $this->clock->now(),
        ]);
    }
}
