<?php

declare(strict_types=1);

namespace App\Application\Plans;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class EditMessage
{
    public function __construct(
        private PlanRepository $plans,
        private PlanMessageRepository $messages,
    ) {}

    /**
     * findVisibleTo() runs first, exactly as SendMessage's own docblock states for the same
     * reason: a non-member's request must answer PlanNotVisible's vague 404 before anything
     * about the message is disclosed, which is the invariant
     * tests/Feature/Security/OrderingAndIdorTest.php enforces for every Plan route with a
     * second path parameter. Without it, a non-member who happens to know a message id -
     * and who authored it before being removed from the Plan - would reach the authorship
     * check below and get NotMessageAuthor's 403 instead, disclosing that the message exists.
     *
     * The planId comparison is what PlanMessageRepository::find()'s own docblock says this
     * use case owns: find() is keyed on $messageId alone, so a message belonging to a
     * different Plan than the one named in the route would otherwise be found and might even
     * belong to this actor - PlanMessageNotFound is what keeps "wrong Plan" and "no such
     * message" indistinguishable, the same reasoning PlanInviteNotFound's docblock gives for
     * {invite}.
     */
    public function execute(Authenticatable $actor, string $planId, string $messageId, string $body): PlanMessageSummary
    {
        $this->plans->findVisibleTo($planId, $actor->getAuthIdentifier());

        $message = $this->messages->find($messageId);

        if ($message === null || $message->planId !== $planId) {
            throw new PlanMessageNotFound;
        }

        if ($message->userId !== $actor->getAuthIdentifier()) {
            throw new NotMessageAuthor;
        }

        return $this->messages->update($messageId, $body);
    }
}
