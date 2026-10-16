<?php

declare(strict_types=1);

use App\Domain\Shared\PageCursor;

it('round-trips a timestamp and an id', function (): void {
    $timestamp = new DateTimeImmutable('2026-10-10 11:22:33.123456+00:00');
    $id = '0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f';

    $encoded = PageCursor::fromKeys($timestamp, $id)->toString();

    expect($encoded)->toBe(
        'MjAyNi0xMC0xMFQxMToyMjozMy4xMjM0NTYrMDA6MDB8MDE5MmYwYTEtYjJjMy03ZDRlLThmOTAtMWEyYjNjNGQ1ZTZm',
        'the cursor encoding is part of the published contract: it is base64url of '
        .'"<Y-m-d\\TH:i:s.uP>|<uuid>" with no padding'
    )
        ->and(PageCursor::fromString($encoded)->id())
        ->toBe($id, 'decoding must return the id it encoded')
        ->and(PageCursor::fromString($encoded)->toString())
        ->toBe($encoded, 'decode-then-encode must be the identity');
});

it('formats the timestamp for a query binding at microsecond precision', function (): void {
    $cursor = PageCursor::fromKeys(
        new DateTimeImmutable('2026-10-10 11:22:33.123456+00:00'),
        '0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f',
    );

    expect($cursor->timestampForQuery())->toBe(
        '2026-10-10 11:22:33.123456+00:00',
        'Illuminate\Database\Grammar::getDateFormat() is Y-m-d H:i:s and PostgresGrammar does '
        .'not override it, so a DateTimeInterface binding loses the microseconds the cursor '
        .'exists to compare; this method is the only thing that formats one'
    );
});

it('distinguishes two rows written one microsecond apart', function (): void {
    $id = '0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f';

    $earlier = PageCursor::fromKeys(new DateTimeImmutable('2026-10-10 11:22:33.123456+00:00'), $id);
    $later = PageCursor::fromKeys(new DateTimeImmutable('2026-10-10 11:22:33.123457+00:00'), $id);

    expect($earlier->toString())->not->toBe(
        $later->toString(),
        'every timestamp column in this schema is timestamptz(6); a cursor format that rounded '
        .'to milliseconds would put two rows on the same page boundary'
    );
});

it('rejects a payload that is not base64url', function (): void {
    expect(fn () => PageCursor::fromString('!!!'))
        ->toThrow(InvalidArgumentException::class, 'Cursor is not base64url.');
});

it('rejects a payload that does not carry exactly two keys', function (): void {
    expect(fn () => PageCursor::fromString(rtrim(strtr(base64_encode('2026-10-10T11:22:33.123456+00:00'), '+/', '-_'), '=')))
        ->toThrow(InvalidArgumentException::class, 'Cursor does not carry exactly two keys.');
});

it('rejects an id that is not a lowercase uuid', function (): void {
    $payload = '2026-10-10T11:22:33.123456+00:00|0192F0A1-B2C3-7D4E-8F90-1A2B3C4D5E6F';

    expect(fn () => PageCursor::fromString(rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')))
        ->toThrow(InvalidArgumentException::class, 'Cursor id is not a lowercase uuid.');
});

it('rejects a timestamp that parses into an invalid date', function (): void {
    $payload = '2026-13-45T11:22:33.123456+00:00|0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f';

    expect(fn () => PageCursor::fromString(rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')))
        ->toThrow(InvalidArgumentException::class, 'Cursor timestamp is not in the cursor format.');
});

it('rejects a millisecond-precision timestamp, which createFromFormat would otherwise accept', function (): void {
    // Measured: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s.uP', '2026-10-10T11:22:33.123+00:00')
    // returns a DateTimeImmutable and getLastErrors() returns false, because `u` accepts one to
    // six digits. It re-encodes as .123000, which is a second spelling of one page boundary.
    $payload = '2026-10-10T11:22:33.123+00:00|0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f';

    expect(fn () => PageCursor::fromString(rtrim(strtr(base64_encode($payload), '+/', '-_'), '=')))
        ->toThrow(InvalidArgumentException::class, 'Cursor is not in canonical form.');
});

it('rejects a padded spelling of a cursor it issued', function (): void {
    // Measured, not assumed: the brief predicted this would reach 'Cursor is not in
    // canonical form.' (the re-encoding comparison), but every valid cursor's payload is
    // exactly 69 bytes - 32 for the fixed-width timestamp (Y-m-d\TH:i:s.uP, including the
    // always-6-char P offset) + 1 for the pipe + 36 for the uuid - and 69 % 3 === 0, so
    // standard base64 of ANY issued cursor needs zero padding characters. Appending even one
    // '=' therefore always makes the length not a multiple of 4, which base64_decode(...,
    // true) rejects outright - before the canonical-form comparison is ever reached, for
    // every possible timestamp/id pair, not just this one. The rejection itself is exactly
    // as intended; only the specific guard that catches it differs from the brief's
    // prediction.
    $encoded = PageCursor::fromKeys(
        new DateTimeImmutable('2026-10-10 11:22:33.123456+00:00'),
        '0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f',
    )->toString();

    expect(fn () => PageCursor::fromString($encoded.'='))
        ->toThrow(InvalidArgumentException::class, 'Cursor is not base64url.');
});
