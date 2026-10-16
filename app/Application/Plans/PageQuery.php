<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Shared\PageCursor;

/**
 * The validated paging inputs of spec §6.2: limit defaults to 25 and is capped at 100.
 *
 * The cap is a 422 rather than a silent clamp. 'max:100' publishes maximum: 100 into the
 * document and refuses 101 with the stock validation body, so the contract and the behaviour
 * agree; clamping would publish a maximum the server ignores, and a client paging at 500 would
 * get 100 rows with no way to find out why.
 *
 * The default lives here and is applied in exactly one place,
 * App\Http\Requests\Concerns\ReadsPageQuery::pageQuery(), so three list endpoints cannot drift
 * to three defaults.
 */
final readonly class PageQuery
{
    public const DEFAULT_LIMIT = 25;

    public const MAX_LIMIT = 100;

    public function __construct(public int $limit, public ?PageCursor $cursor) {}
}
