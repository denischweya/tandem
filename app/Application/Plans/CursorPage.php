<?php

declare(strict_types=1);

namespace App\Application\Plans;

/**
 * One page of a keyset list: the items, and the cursor that fetches the next page or null when
 * this page is the last.
 *
 * $nextCursor is ?string rather than ?PageCursor because the only thing that reads it is the
 * controller's ->additional(['meta' => ['next_cursor' => …]]), and spec §6.2 publishes it as an
 * opaque string. It is non-null exactly when the repository read one row more than the page
 * size - which is why every repository in this wave asks for $limit + 1 rows.
 *
 * @template TItem
 */
final readonly class CursorPage
{
    /** @param list<TItem> $items */
    public function __construct(public array $items, public ?string $nextCursor) {}
}
