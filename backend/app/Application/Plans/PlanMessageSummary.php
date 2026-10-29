<?php

declare(strict_types=1);

namespace App\Application\Plans;

use DateTimeImmutable;

final readonly class PlanMessageSummary
{
    /** @param list<PlanMessageReactionSummary> $reactions */
    public function __construct(
        public string $id,
        public string $planId,
        public string $userId,
        public string $body,
        public ?DateTimeImmutable $editedAt,
        public DateTimeImmutable $createdAt,
        public DateTimeImmutable $updatedAt,
        public array $reactions,
    ) {}
}
