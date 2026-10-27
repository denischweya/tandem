<?php

declare(strict_types=1);

namespace App\Domain\Users;

use InvalidArgumentException;

final class Email
{
    private const MAX_LENGTH = 254;

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = strtolower(trim($value));

        if ($normalized === '' || strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Email address is empty or too long.');
        }

        if (filter_var($normalized, FILTER_VALIDATE_EMAIL) === false) {
            throw new InvalidArgumentException('Email address is not valid.');
        }

        return new self($normalized);
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
