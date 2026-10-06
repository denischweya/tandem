<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeZone;
use InvalidArgumentException;

final class TimeZoneId
{
    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        $trimmed = trim($value);

        if ($trimmed === '') {
            throw new InvalidArgumentException('Timezone must not be empty.');
        }

        if (! in_array($trimmed, DateTimeZone::listIdentifiers(DateTimeZone::ALL), true)) {
            throw new InvalidArgumentException("Not an IANA timezone identifier: {$trimmed}");
        }

        return new self($trimmed);
    }

    public function toString(): string
    {
        return $this->value;
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }
}
