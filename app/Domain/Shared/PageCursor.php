<?php

declare(strict_types=1);

namespace App\Domain\Shared;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * The one cursor shape spec §6.2 specifies for all three paginated lists: an opaque base64url
 * string carrying the two keys of a keyset page boundary, a timestamp and a uuid.
 *
 * Microseconds, not DATE_RFC3339_EXTENDED. Every timestamp column in this schema is
 * timestamptz(6) - the plan-tables migration's header says why precision 0 was rejected - and
 * DATE_RFC3339_EXTENDED is Y-m-d\TH:i:s.vP, milliseconds, which would put two rows written 400
 * microseconds apart on the same page boundary. The published resources still render
 * DATE_RFC3339_EXTENDED, matching SessionResource; a client never reconstructs a cursor from a
 * rendered timestamp, because the server issues every cursor it accepts.
 *
 * Deliberately unsigned. A cursor only moves the page boundary inside a result set the
 * repository has already filtered by membership, so a tampered cursor cannot reach a row the
 * actor could not otherwise read. Signing it would need Crypt, which app/Domain may not import:
 * the arch rule is toOnlyUse(['App\Domain','DateTimeImmutable','DateTimeZone',
 * 'InvalidArgumentException']) and three phpstan-rules/DomainPurity rules close the gaps in it.
 */
final class PageCursor
{
    /** The encoded form. `u` is microseconds; DATE_RFC3339_EXTENDED's `v` is milliseconds. */
    public const FORMAT = 'Y-m-d\TH:i:s.uP';

    /**
     * The binding form, which is NOT the encoded form.
     *
     * Illuminate\Database\Grammar::getDateFormat() returns 'Y-m-d H:i:s' and PostgresGrammar
     * does not override it, while Connection::prepareBindings() formats every
     * DateTimeInterface binding with it - so handing a DateTimeImmutable to a keyset predicate
     * truncates exactly the microseconds this class exists to compare, silently, and page two
     * drops every row that shares its second with the last row of page one. Every repository
     * binds timestampForQuery() instead, and nothing else formats a cursor timestamp.
     */
    private const QUERY_FORMAT = 'Y-m-d H:i:s.uP';

    private const ID_PATTERN = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    private function __construct(
        private readonly DateTimeImmutable $timestamp,
        private readonly string $id,
    ) {}

    public static function fromKeys(DateTimeImmutable $timestamp, string $id): self
    {
        if (preg_match(self::ID_PATTERN, $id) !== 1) {
            throw new InvalidArgumentException('Cursor id is not a lowercase uuid.');
        }

        return new self($timestamp, $id);
    }

    public static function fromString(string $encoded): self
    {
        $decoded = base64_decode(strtr($encoded, '-_', '+/'), true);

        if ($decoded === false) {
            throw new InvalidArgumentException('Cursor is not base64url.');
        }

        $parts = explode('|', $decoded);

        if (count($parts) !== 2) {
            throw new InvalidArgumentException('Cursor does not carry exactly two keys.');
        }

        $timestamp = DateTimeImmutable::createFromFormat(self::FORMAT, $parts[0]);

        // getLastErrors() is checked as well as the false return, because createFromFormat()
        // returns an object for an overflowing date and reports it only as a warning:
        // '2026-13-45T…' parses to 2027-02-14 with warning_count 1, 'The parsed date was
        // invalid'. Measured, not assumed.
        if ($timestamp === false || DateTimeImmutable::getLastErrors() !== false) {
            throw new InvalidArgumentException('Cursor timestamp is not in the cursor format.');
        }

        $cursor = self::fromKeys($timestamp, $parts[1]);

        // Canonical form, and this check is load-bearing rather than tidy. `u` in
        // createFromFormat() accepts one to six digits, so '…11:22:33.123+00:00' - which is
        // what DATE_RFC3339_EXTENDED produces, i.e. what a client reading a rendered
        // created_at would try - parses with no error and re-encodes as '…11:22:33.123000'.
        // Two spellings of one boundary is two page sequences. Comparing the re-encoding
        // against the input makes fromString(toString()) the only accepted spelling, and it
        // also rejects the padded and standard-base64 spellings of the same bytes.
        if ($cursor->toString() !== $encoded) {
            throw new InvalidArgumentException('Cursor is not in canonical form.');
        }

        return $cursor;
    }

    public function toString(): string
    {
        $payload = $this->timestamp->format(self::FORMAT).'|'.$this->id;

        return rtrim(strtr(base64_encode($payload), '+/', '-_'), '=');
    }

    public function timestampForQuery(): string
    {
        return $this->timestamp->format(self::QUERY_FORMAT);
    }

    public function id(): string
    {
        return $this->id;
    }
}
