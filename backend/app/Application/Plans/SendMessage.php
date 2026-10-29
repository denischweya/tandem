<?php

declare(strict_types=1);

namespace App\Application\Plans;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class SendMessage
{
    public function __construct(
        private PlanRepository $plans,
        private PlanMessageRepository $messages,
    ) {}

    /**
     * findVisibleTo() throws PlanNotVisible for a malformed {plan}, an unknown Plan, or an
     * actor who is not an active member - one exception, one 404 body, already rendered in
     * bootstrap/app.php and reused rather than re-implemented. Its return is not used: there
     * is nothing in the PlanContext this method needs, so it is called only for the visibility
     * check's throw - $planId and $actor's own id are what travel on to the repository.
     */
    public function execute(Authenticatable $actor, string $planId, string $body): PlanMessageSummary
    {
        $this->plans->findVisibleTo($planId, $actor->getAuthIdentifier());

        return $this->messages->create($planId, $actor->getAuthIdentifier(), $body);
    }
}
