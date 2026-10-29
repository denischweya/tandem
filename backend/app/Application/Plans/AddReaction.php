<?php

declare(strict_types=1);

namespace App\Application\Plans;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class AddReaction
{
    public function __construct(
        private PlanRepository $plans,
        private PlanMessageRepository $messages,
    ) {}

    /**
     * findVisibleTo() runs first, exactly as EditMessage's own docblock states for the same
     * reason: a non-member's request must answer PlanNotVisible's vague 404 before anything
     * about the message is disclosed. The planId comparison below is the same
     * PlanMessageNotFound guard EditMessage and DeleteMessage both apply, and for the same
     * reason - find() is keyed on $messageId alone, so a message belonging to a different Plan
     * than the one named in the route would otherwise be found.
     *
     * Unlike EditMessage and DeleteMessage, there is no authorship check here: per the chat
     * feature's own design, reacting is open to every active member of the Plan, regardless of
     * who sent the message and regardless of the reactor's role. findVisibleTo()'s own
     * membership resolution IS the authorization, the same reasoning SendMessage's and
     * ListPlanMessages's own docblocks give for their reads and writes.
     */
    public function execute(Authenticatable $actor, string $planId, string $messageId, string $reaction): void
    {
        $this->plans->findVisibleTo($planId, $actor->getAuthIdentifier());

        $message = $this->messages->find($messageId);

        if ($message === null || $message->planId !== $planId) {
            throw new PlanMessageNotFound;
        }

        $this->messages->addReaction($messageId, $actor->getAuthIdentifier(), $reaction);
    }
}
