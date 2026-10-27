<?php

declare(strict_types=1);

namespace App\Domain\Users;

use InvalidArgumentException;

final class DisplayName
{
    private const MAX_LENGTH = 80;

    private function __construct(private readonly string $value) {}

    public static function fromString(string $value): self
    {
        $normalized = trim(preg_replace('/\s+/u', ' ', $value) ?? '');

        if ($normalized === '') {
            throw new InvalidArgumentException('Display name must not be empty.');
        }

        if (mb_strlen($normalized) > self::MAX_LENGTH) {
            throw new InvalidArgumentException('Display name is too long.');
        }

        return new self($normalized);
    }

    public function toString(): string
    {
        return $this->value;
    }
}
