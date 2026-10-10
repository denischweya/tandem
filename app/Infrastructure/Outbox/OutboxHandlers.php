<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

use LogicException;

/**
 * Registered as a singleton, so handlers registered at boot (or in a test) are visible to
 * every dispatcher resolved afterwards.
 */
final class OutboxHandlers
{
    /** @var array<string, callable(array<string, mixed>): void> */
    private array $handlers = [];

    /**
     * Throws on a duplicate event type, where it used to overwrite silently.
     *
     * The three registration sites in AppServiceProvider are independent calls on one shared
     * singleton, so a typo or a copy-paste between them replaced a live handler with another
     * one and reported nothing: registering the no-op `user.deleted` closure under
     * `user.registered` would have deleted every verification email in the application, with
     * the suite green and the dispatcher happily marking events published. That hazard is
     * currently described in a comment at the third registration site, which is the wrong
     * place for it - a comment cannot be the mechanism.
     *
     * A LogicException and not a caught-and-logged warning: this can only ever be a
     * programming error, it is decidable at boot, and the dispatcher is resolved from a
     * scheduled command where a half-registered handler table is exactly the condition that
     * must stop the process rather than run with it.
     *
     * @param  callable(array<string, mixed>): void  $handler
     */
    public function register(string $eventType, callable $handler): void
    {
        if (isset($this->handlers[$eventType])) {
            throw new LogicException(
                "An outbox handler is already registered for event type [{$eventType}]. "
                .'Registering a second one would silently replace the first. Use override() if '
                .'replacing it is what you mean.'
            );
        }

        $this->handlers[$eventType] = $handler;
    }

    /**
     * Replaces an already-registered handler, deliberately.
     *
     * This exists so that the one legitimate reason to write twice to the same key - a test
     * swapping a real handler for a probe - is spelled differently from the accident
     * register() now refuses. Its only callers today are in tests/Feature/Outbox; production
     * code has no reason to replace a handler it has just registered.
     *
     * @param  callable(array<string, mixed>): void  $handler
     */
    public function override(string $eventType, callable $handler): void
    {
        $this->handlers[$eventType] = $handler;
    }

    /** @return (callable(array<string, mixed>): void)|null */
    public function handlerFor(string $eventType): ?callable
    {
        return $this->handlers[$eventType] ?? null;
    }
}
