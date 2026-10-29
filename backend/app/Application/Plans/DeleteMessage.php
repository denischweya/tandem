<?php

declare(strict_types=1);

namespace App\Application\Plans;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class DeleteMessage
{
    public function __construct(
        private PlanRepository $plans,
        private PlanMessageRepository $messages,
    ) {}

    /** Identical shape to EditMessage::execute() - see its own docblock for both checks' reasons. */
    public function execute(Authenticatable $actor, string $planId, string $messageId): void
    {
        $this->plans->findVisibleTo($planId, $actor->getAuthIdentifier());

        $message = $this->messages->find($messageId);

        if ($message === null || $message->planId !== $planId) {
            throw new PlanMessageNotFound;
        }

        if ($message->userId !== $actor->getAuthIdentifier()) {
            throw new NotMessageAuthor;
        }

        $this->messages->softDelete($messageId);
    }
}
