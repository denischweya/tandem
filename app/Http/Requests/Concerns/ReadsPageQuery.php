<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Application\Plans\PageQuery;
use App\Domain\Shared\PageCursor;

/**
 * The paging half of every list FormRequest in this wave.
 *
 * It declares pageQuery() and nothing else, for the reason recorded in NormalizesEmailInput:
 * PHP resolves a class-declared method over a trait's with no error or warning, so a trait
 * that declared rules() or prepareForValidation() would be discarded the moment a using class
 * declared its own. pageQuery() is a name no FormRequest has a reason to declare.
 *
 * It must be called only after validation - the controller's one call site. The 'cursor' rule
 * is ValidCursor, so by the time a controller runs, PageCursor::fromString() cannot throw; call
 * it from prepareForValidation() and a malformed cursor becomes a 500.
 *
 * `limit` has the identical obligation and nothing enforces it yet. PageQuery::MAX_LIMIT exists
 * only as a number a FUTURE rule can cite - this trait does not clamp or refuse against it, so
 * as of this commit `?limit=100000` reaches the repository unchanged. Each using FormRequest's
 * own rules() must add `'limit' => ['sometimes', 'integer', 'min:1', 'max:'.PageQuery::MAX_LIMIT]`
 * (D-R6: a 422, not a silent clamp) - deliberately no `nullable`, because
 * Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull sits in the global middleware
 * stack and turns `?limit=` into a present `null`, which `integer` already refuses on its own.
 */
trait ReadsPageQuery
{
    public function pageQuery(): PageQuery
    {
        $cursor = $this->string('cursor')->toString();

        return new PageQuery(
            $this->integer('limit', PageQuery::DEFAULT_LIMIT),
            $cursor === '' ? null : PageCursor::fromString($cursor),
        );
    }
}
