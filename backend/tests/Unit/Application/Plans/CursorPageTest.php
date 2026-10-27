<?php

declare(strict_types=1);

use App\Application\Plans\CursorPage;
use App\Domain\Shared\PageCursor;

// Not in Task 6's own brief, and the reason is itself a finding: CursorPage has no consumer
// yet, and - unlike ReadsPageQuery - PHPStan's trait.unused has no class-level equivalent, so
// an unexercised @template TItem generic, list<TItem> contract and ?string $nextCursor
// nullability would ship with zero assertions anywhere, in the one task that owns the class.
//
// The three boundary shapes below are the three a repository's $limit+1 read actually
// produces (D-R1/CursorPage's own docblock: non-null nextCursor exactly when one extra row
// was read): a result set that fits inside one page (no further page), a result set with
// exactly one row (same case, the degenerate end of it), and a result set with more rows
// waiting (next page exists). An implementation that swapped the constructor's two
// parameters, or that lost array values across the boundary, would fail each of these by
// comparing items/nextCursor independently rather than only checking "did it throw".
it('holds an empty page with no further page', function (): void {
    $page = new CursorPage([], null);

    expect($page->items)->toBe([])
        ->and($page->nextCursor)->toBeNull();
});

it('holds a single-row page that is also the last page', function (): void {
    $page = new CursorPage(['only-row'], null);

    expect($page->items)->toBe(['only-row'])
        ->and($page->nextCursor)->toBeNull();
});

it('holds a page with more rows available, carrying the cursor to fetch them', function (): void {
    $cursor = PageCursor::fromKeys(
        new DateTimeImmutable('2026-10-10 11:22:33.123456+00:00'),
        '0192f0a1-b2c3-7d4e-8f90-1a2b3c4d5e6f',
    )->toString();

    $page = new CursorPage(['row-1', 'row-2'], $cursor);

    expect($page->items)->toBe(['row-1', 'row-2'], 'the item array must round-trip in order and by value')
        ->and($page->nextCursor)->toBe($cursor, 'nextCursor must be the opaque string spec §6.2 publishes, not re-derived');
});

it('is immutable once constructed', function (): void {
    // "final readonly class" is one of this class's own Produces lines, not a style
    // preference: a mutable CursorPage would let a controller mutate a page after a
    // repository returned it, which is exactly the kind of drift the readonly value-object
    // pattern (PageQuery, PageCursor) exists to rule out across this whole task.
    //
    // Via ReflectionProperty::setValue(), not `$page->items = [...]` directly: PHPStan
    // already proves the direct form impossible at level 8 (assign.propertyProtectedSet) for
    // every call site in app/ and tests/, which is a stronger, earlier guarantee than a test
    // reaching it at runtime - but it also means the direct form cannot appear in this file
    // without failing `make stan`. Reflection bypasses the static check and still reaches the
    // PHP ENGINE's own readonly enforcement, confirmed by probe to throw the same Error.
    $page = new CursorPage(['only-row'], null);

    expect(fn () => (new ReflectionProperty($page, 'items'))->setValue($page, ['replaced']))
        ->toThrow(Error::class, 'Cannot modify readonly property App\Application\Plans\CursorPage::$items');
});
