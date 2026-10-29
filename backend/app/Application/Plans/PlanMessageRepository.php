<?php

declare(strict_types=1);

namespace App\Application\Plans;

interface PlanMessageRepository
{
    public function create(string $planId, string $userId, string $body): PlanMessageSummary;

    /**
     * $viewerId is unused by every implementation until Task 4 populates
     * PlanMessageSummary::$reactions for real - it is part of the interface now so that
     * change needs no call-site update. Until then every returned summary carries reactions: [].
     *
     * @return CursorPage<PlanMessageSummary>
     */
    public function listForPlan(string $planId, string $viewerId, PageQuery $query): CursorPage;
}
