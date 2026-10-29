<?php

declare(strict_types=1);

namespace App\Application\Plans;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class RemoveReaction
{
    public function __construct(
        private PlanRepository $plans,
        private PlanMessageRepository $messages,
    ) {}

    /**
     * Identical shape to AddReaction::execute() - see its own docblock for both the ordering
     * and the "no authorship check" reasoning. The one difference is that removeReaction()
     * itself is already a no-op on a $reaction that is not there (see its own interface
     * docblock), so this method does not need to - and must not - distinguish "removed" from
     * "there was nothing to remove": both are the same successful no-content answer.
     */
    public function execute(Authenticatable $actor, string $planId, string $messageId, string $reaction): void
    {
        $this->plans->findVisibleTo($planId, $actor->getAuthIdentifier());

        $message = $this->messages->find($messageId);

        if ($message === null || $message->planId !== $planId) {
            throw new PlanMessageNotFound;
        }

        $this->messages->removeReaction($messageId, $actor->getAuthIdentifier(), $reaction);
    }
}
