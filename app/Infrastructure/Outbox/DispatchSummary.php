<?php

declare(strict_types=1);

namespace App\Infrastructure\Outbox;

final readonly class DispatchSummary
{
    public function __construct(
        public int $published,
        public int $failed,
    ) {}
}
