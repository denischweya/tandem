<?php

declare(strict_types=1);

use App\Domain\Users\DisplayName;

it('accepts a normal name', function (): void {
    expect(DisplayName::fromString('Denis Okwir')->toString())->toBe('Denis Okwir');
});

it('collapses internal whitespace', function (): void {
    expect(DisplayName::fromString("Denis   \t Okwir")->toString())->toBe('Denis Okwir');
});

it('rejects an empty name', function (): void {
    expect(fn () => DisplayName::fromString(''))->toThrow(InvalidArgumentException::class);
});

it('rejects a whitespace-only name', function (): void {
    expect(fn () => DisplayName::fromString("   \t  "))->toThrow(InvalidArgumentException::class);
});

it('rejects a name over 80 characters', function (): void {
    expect(fn () => DisplayName::fromString(str_repeat('a', 81)))
        ->toThrow(InvalidArgumentException::class);
});

it('accepts exactly 80 multibyte characters, matching the varchar(80) column', function (): void {
    // user_profiles.display_name is varchar(80): Postgres counts *characters*, not bytes,
    // so a multibyte boundary case must agree with mb_strlen, not strlen. 'é' is two bytes
    // in UTF-8 but one character - strlen(str_repeat('é', 80)) would be 160.
    $name = str_repeat('é', 80);

    expect(DisplayName::fromString($name)->toString())->toBe($name);
});

it('rejects 81 multibyte characters, one over the varchar(80) boundary', function (): void {
    expect(fn () => DisplayName::fromString(str_repeat('é', 81)))
        ->toThrow(InvalidArgumentException::class);
});
