<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

/**
 * Registered as a singleton, so handlers registered at boot (or in a test) are visible to
 * every dispatcher resolved afterwards.
 */
final class OutboxHandlers
{
    /** @var array<string, callable(array<string, mixed>): void> */
    private array $handlers = [];

    /** @param callable(array<string, mixed>): void $handler */
    public function register(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType] = $handler;
    }

    /** @return (callable(array<string, mixed>): void)|null */
    public function handlerFor(string $eventType): ?callable
    {
        return $this->handlers[$eventType] ?? null;
    }
}
