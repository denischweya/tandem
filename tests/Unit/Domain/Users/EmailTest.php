<?php

declare(strict_types=1);

use App\Domain\Users\Email;

it('normalizes case and surrounding whitespace', function (): void {
    expect(Email::fromString('  Denis@Example.COM ')->toString())->toBe('denis@example.com');
});

it('rejects a malformed address', function (): void {
    expect(fn () => Email::fromString('not-an-email'))->toThrow(InvalidArgumentException::class);
});

it('rejects an address that is too long for the column', function (): void {
    expect(fn () => Email::fromString(str_repeat('a', 250).'@example.com'))
        ->toThrow(InvalidArgumentException::class);
});
