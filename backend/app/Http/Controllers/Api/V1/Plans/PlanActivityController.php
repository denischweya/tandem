<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\ListPlanActivity;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\ListPlanActivityRequest;
use App\Http\Resources\Api\V1\Plans\PlanActivityResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlanActivityController
{
    use RequiresAuthenticatedUser;

    // A plain comment, not a docblock: a controller method docblock is harvested by Scramble
    // into the operation's published `summary` - measured, and the reason GetPlanController
    // carries the same instruction in its own file.
    //
    // The 404 is declared because Scramble cannot see an exception-render closure in
    // bootstrap/app.php. The 401 and the 422 are inferred (auth:sanctum, and the FormRequest's
    // rules), so they are not declared here. array{message: string} rather than a bare string:
    // that is the body the closure actually returns, and without `type` the attribute publishes
    // `type: string`, which would be a new false statement in place of a missing one.
    //
    // string $plan arrives SECOND, between the two class-typed parameters, matching every other
    // Plan controller action that takes a path parameter plus a use case
    // (ResolvesRouteDependencies::resolveMethodDependencies() splices class-typed parameters into
    // the route's own parameter array by position and fills the rest from it, so the position of
    // the raw string among them is cosmetic - matching the siblings is what keeps this file
    // unsurprising to someone who has just read one of them). $request->route('plan') is not
    // used instead: it returns mixed and would need an is_string() narrowing in a controller
    // whose house style has no branch in it at all.
    #[Response(status: 404, description: 'No Plan with this id is visible to the signed-in user. A Plan that does not exist and a Plan the user is not an active member of answer identically.', type: 'array{message: string}')]
    public function __invoke(
        ListPlanActivityRequest $request,
        string $plan,
        ListPlanActivity $listPlanActivity,
    ): AnonymousResourceCollection {
        $user = $this->requireAuthenticatedUser($request->user(), 'GET /plans/{plan}/activity');

        $page = $listPlanActivity->execute($user, $plan, $request->pageQuery());

        // Not a bare `$page->nextCursor`: Scramble's ClassAnalyzer wraps every non-static
        // property of any analysed class - including CursorPage, the shared page type for every
        // list in this wave - in its own unresolved per-property template type, and handing that
        // straight to additional() fails `make openapi` with "[SE001] Schema UnknownType is not
        // allowed" under --fail-on-unknown. No generic argument, a declared accessor on
        // CursorPage, and removing CursorPage's own @template tag entirely were each measured
        // and each fail identically, so the fix is confined to this read site rather than to the
        // shared DTO. The ternary forces a fresh, concrete type per branch - a literal null, and
        // a `(string)` cast whose result type does not depend on the unresolved template of its
        // operand. The cast is load-bearing: the ternary without it fails identically, and
        // without either the document would publish next_cursor as a bare {"type":"string"},
        // losing nullability from the contract instead of refusing to generate it.
        // ListPlansController and PlanMemberController carry the identical device for the same
        // reason.
        return PlanActivityResource::collection($page->items)
            ->additional(['meta' => ['next_cursor' => $page->nextCursor === null ? null : (string) $page->nextCursor]]);
    }
}
