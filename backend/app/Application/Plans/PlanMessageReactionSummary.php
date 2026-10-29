<?php

declare(strict_types=1);

namespace App\Application\Plans;

final readonly class PlanMessageReactionSummary
{
    public function __construct(
        public string $reaction,
        public int $count,
        public bool $reactedByViewer,
    ) {}
}
