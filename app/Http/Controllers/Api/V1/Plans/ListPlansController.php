<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\ListPlans;
use App\Application\Plans\PlanSummary;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\ListPlansRequest;
use App\Http\Resources\Api\V1\Plans\PlanResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListPlansController
{
    use RequiresAuthenticatedUser;

    // No #[Response] attributes: this operation raises no status Scramble cannot see. The 401
    // comes from auth:sanctum, which Scramble reads off the route's middleware, and the 422
    // from ListPlansRequest's rules - including the malformed-cursor case, which ValidCursor
    // refuses before this method ever runs. There is deliberately no 403 and no 404 - see the
    // use case's own docblock.
    //
    // $request->status() and $request->pageQuery() rather than reading 'status'/'limit'/
    // 'cursor' here: both already exist on ListPlansRequest, and re-deriving either inline
    // would be a second copy of logic the FormRequest already owns.
    //
    // PlanSummary::fromContext() per item: the repository returns a page of the context type
    // the repositories section produces, which is not Resource-mixin-safe on its own (it nests
    // a Plan model rather than declaring its own scalar properties) - see PlanSummary's own
    // docblock.
    //
    // ->additional(['meta' => ...]) and not a hand-assembled array or response()->json([...]):
    // Scramble reads additional()'s argument as template index 1 of the resource's Generic
    // type, so a hand-built envelope goes down a different inference path and publishes a
    // different schema.
    //
    // next_cursor is NOT read as a bare `$page->nextCursor`, and that is measured rather than
    // a style choice: the page DTO carries a `@template` tag, and Scramble's ClassAnalyzer
    // wraps every one of its non-static properties - items AND nextCursor alike - in its own
    // auto-generated per-property template type, bounded by that property's native/phpdoc
    // type. This use case's own `@return` tag supplies one generic argument, which resolves
    // only the template bound to `items`; the second, unrelated template synthesised for
    // `nextCursor` is left unsubstituted, and handing that straight to `additional()` makes
    // `artisan scramble:export --fail-on-unknown` refuse the whole document with
    // "Schema `UnknownType` is not allowed" at this exact line. The ternary forces a fresh,
    // concrete type per branch instead - a literal null, and a `(string)` cast whose result
    // type does not depend on the unresolved template of its operand - which is the same
    // device PlanResource's three date fields already use, for the same reason, one property
    // deeper. The cast is a no-op at runtime: the branch it sits in already excludes null.
    public function __invoke(ListPlansRequest $request, ListPlans $listPlans): AnonymousResourceCollection
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'GET /plans');

        $page = $listPlans->execute($user, $request->status(), $request->pageQuery());

        return PlanResource::collection(array_map(PlanSummary::fromContext(...), $page->items))
            ->additional(['meta' => ['next_cursor' => $page->nextCursor === null ? null : (string) $page->nextCursor]]);
    }
}
