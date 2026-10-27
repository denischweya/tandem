<?php

declare(strict_types=1);

use App\Domain\Shared\TimeZoneId;

it('accepts a valid IANA identifier', function (): void {
    expect(TimeZoneId::fromString('Africa/Nairobi')->toString())->toBe('Africa/Nairobi');
});

it('rejects a non-IANA identifier', function (): void {
    expect(fn () => TimeZoneId::fromString('EST5EDT-nonsense'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects a UTC offset masquerading as a timezone', function (): void {
    expect(fn () => TimeZoneId::fromString('+03:00'))
        ->toThrow(InvalidArgumentException::class);
});

it('rejects an empty identifier', function (): void {
    expect(fn () => TimeZoneId::fromString(''))
        ->toThrow(InvalidArgumentException::class);
});

it('compares by value', function (): void {
    expect(TimeZoneId::fromString('Europe/London')->equals(TimeZoneId::fromString('Europe/London')))
        ->toBeTrue()
        ->and(TimeZoneId::fromString('Europe/London')->equals(TimeZoneId::fromString('Asia/Dubai')))
        ->toBeFalse();
});
