<?php

declare(strict_types=1);

use App\Application\Plans\PageQuery;
use App\Domain\Shared\PageCursor;

// Not in the brief's own Test files list, but the trait is a Produces deliverable with a real
// signature (pageQuery(): PageQuery) that nothing else in this task exercises - and with no
// consumer and no test, PHPStan's trait.unused check ("Trait … is used zero times and is not
// analysed") fires on app/Http/Requests/Concerns/ReadsPageQuery.php, which make stan reports as
// a real error, not a baseline-suppressible one. `PageQueryTestRequest`, declared once in
// tests/Pest.php (see its docblock there), is what resolves that.
//
// Fix round 1 correction: an earlier version of this comment claimed "a real HTTP query string
// never sends a present-but-null key - an omitted ?limit= param arrives absent, not null".
// Measured false for this application. Illuminate\Foundation\Http\Middleware\
// ConvertEmptyStringsToNull is in Laravel's default global middleware stack
// (vendor/laravel/framework/.../Configuration/Middleware.php:462) and bootstrap/app.php never
// removes or replaces the stack (it only adds the `verified` alias) -
// TransformsRequest::clean() runs ConvertEmptyStringsToNull against $request->query
// unconditionally, and ConvertEmptyStringsToNull::transform() is `$value === '' ? null :
// $value`. So `?limit=&cursor=` arrives as `['limit' => null, 'cursor' => null]` - a PRESENT
// null, never an absent key and never an empty string. The case below that used to claim to
// model `?cursor=` was testing a pre-middleware shape this application cannot actually
// deliver; it is kept as a direct test of pageQuery()'s own input()-based contract (still a
// real, reachable path for a non-HTTP caller), and a second case below tests the shape the
// router actually produces.
it('defaults the limit and the cursor when neither query parameter is present', function (): void {
    $pageQuery = (new PageQueryTestRequest([]))->pageQuery();

    expect($pageQuery->limit)->toBe(
        PageQuery::DEFAULT_LIMIT,
        sprintf('an ABSENT ?limit= (no key at all) means the published default of %d', PageQuery::DEFAULT_LIMIT)
    )
        ->and($pageQuery->cursor)->toBeNull('no ?cursor= means the first page');
});

it("treats pageQuery()'s own empty-string input contract the same as an absent key", function (): void {
    // Not a claim about what the router sends - see the file docblock above - but a real,
    // separately reachable path: pageQuery() reads $this->string('cursor')->toString(), and
    // an empty string is a legal value for that call regardless of HTTP, so it must still
    // resolve to "no cursor" rather than reaching PageCursor::fromString('').
    $pageQuery = (new PageQueryTestRequest(['cursor' => '']))->pageQuery();

    expect($pageQuery->cursor)->toBeNull();
});

it('does not fall back to the default limit for a present-but-null query parameter', function (): void {
    // The shape ConvertEmptyStringsToNull actually produces for `?cursor=&limit=` (see the
    // file docblock above): present keys, null values. cursor correctly resolves to "no
    // cursor" either way, but limit does NOT get DEFAULT_LIMIT here - Request::input()'s
    // default argument applies only when the key is ABSENT, never when it is present and
    // null, so $this->integer('limit', 25) sees the present null and returns intval(null) ===
    // 0. This is the asymmetry the Important-3 docblock addition to ReadsPageQuery names:
    // nothing validates `limit` yet, so this 0 reaches PageQuery unrefused.
    $pageQuery = (new PageQueryTestRequest(['cursor' => null, 'limit' => null]))->pageQuery();

    expect($pageQuery->cursor)->toBeNull()
        ->and($pageQuery->limit)->toBe(0, 'a present null does not fall back to DEFAULT_LIMIT - only an absent key does');
});

it('decodes an explicit limit and a cursor this API issued', function (): void {
    $encoded = PageCursor::fromKeys(
        new DateTimeImmutable('2026-10-10 11:22:33.123456+00:00'),
        '0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f',
    )->toString();

    $pageQuery = (new PageQueryTestRequest(['limit' => '10', 'cursor' => $encoded]))->pageQuery();
    $cursor = $pageQuery->cursor;

    // A plain instanceof, not chained off the expect() above: PHPStan cannot narrow
    // ?PageCursor through Pest's fluent expectation API, only through a real PHP type guard,
    // and calling ->toString() on the nullable property directly is method.nonObject at
    // level 8.
    expect($pageQuery->limit)->toBe(10)
        ->and($cursor)->toBeInstanceOf(PageCursor::class);

    if ($cursor instanceof PageCursor) {
        expect($cursor->toString())->toBe($encoded, 'the trait must not re-derive the cursor, only decode it');
    }
});
