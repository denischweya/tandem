<?php

declare(strict_types=1);

use App\Application\Plans\PageQuery;
use App\Domain\Shared\PageCursor;
use App\Http\Requests\Concerns\ReadsPageQuery;
use Illuminate\Http\Request;

// Not in the brief's own Test files list, but the trait is a Produces deliverable with a real
// signature (pageQuery(): PageQuery) that nothing else in this task exercises - and with no
// consumer and no test, PHPStan's trait.unused check ("Trait … is used zero times and is not
// analysed") fires on app/Http/Requests/Concerns/ReadsPageQuery.php, which make stan reports as
// a real error, not a baseline-suppressible one. A named class here (rather than the anonymous
// one tried first) is what resolves that cleanly: PHPStan narrows an anonymous class's own type
// from a return EXPRESSION, but not through a helper function's declared return type - typing
// that `: Request` hid the trait's method again, and a trait cannot be named in a PHPDoc
// intersection type either (`return.unresolvableType`). A concrete subclass sidesteps both.
// Illuminate\Http\Request's query-string methods (string(), integer()) need no booted
// container, so this stays inside the Unit suite's "boots no application" rule.
//
// $this->integer('limit', PageQuery::DEFAULT_LIMIT) is $this->input('limit', 25) under the
// hood: input()'s default applies only when the key is ABSENT, never when it is present and
// null. A real HTTP query string never sends a present-but-null key - an omitted ?limit= param
// arrives absent, not null - so every case below either omits the key or gives it a string
// value, matching what the router actually produces.
final class PageQueryTestRequest extends Request
{
    use ReadsPageQuery;
}

it('defaults the limit and the cursor when neither query parameter is present', function (): void {
    $pageQuery = (new PageQueryTestRequest([]))->pageQuery();

    expect($pageQuery->limit)->toBe(PageQuery::DEFAULT_LIMIT, 'no ?limit= means the published default of 25')
        ->and($pageQuery->cursor)->toBeNull('no ?cursor= means the first page');
});

it('treats an empty cursor the same as an absent one', function (): void {
    // ?cursor= (present, empty) is how a client that always includes the param, or follows a
    // link templated with an empty value, asks for the first page - it must not reach
    // PageCursor::fromString(''), which does not special-case the empty string.
    $pageQuery = (new PageQueryTestRequest(['cursor' => '']))->pageQuery();

    expect($pageQuery->cursor)->toBeNull();
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
