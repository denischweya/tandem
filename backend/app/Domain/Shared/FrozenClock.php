<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeImmutable;

final class FrozenClock implements Clock
{
    public function __construct(private readonly DateTimeImmutable $at) {}

    public function now(): DateTimeImmutable
    {
        return $this->at;
    }
}
