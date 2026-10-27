<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\AcceptInvite;
use App\Application\Plans\PlanSummary;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\JoinPlanRequest;
use App\Http\Resources\Api\V1\Plans\PlanResource;
use Dedoc\Scramble\Attributes\Response;

final class AcceptInviteController
{
    use RequiresAuthenticatedUser;

    // Two declared statuses beyond what Scramble infers (200 from the PlanResource return type,
    // 401 from auth:sanctum, 422 from the FormRequest): both the 404 and the 409 below are bare
    // RuntimeException subclasses mapped by a render() closure in bootstrap/app.php, which
    // Scramble's own exception-to-response extensions do not recognise, so neither publishes
    // without being authored here.
    //
    // No 403: this route carries no `verified` middleware (§7.5) and performs no role check, so
    // there is no authorization failure to document. No 429: §13.4 gives it no limit.
    //
    // {plan} is a raw string, never a type-hinted Plan: SubstituteBindings sorts AFTER
    // ThrottleRequests and before the controller, so an implicit binding would make a missing Plan
    // throw ModelNotFoundException and emit Laravel's own PRETTY-PRINTED 404 body - a second 404
    // body on a route whose entire design is that its refusals are indistinguishable.
    #[Response(status: 404, description: 'The invite cannot be used: the token is unknown, malformed, expired, revoked or already accepted, it was issued for a different Plan, or the Plan is no longer active. One body for every case, so a captured link reveals nothing about which.', type: 'array{message: string}')]
    #[Response(status: 409, description: 'The invite carries the owner role, which would make the accepting user a second owner of this Plan. Reachable only by a row written outside the API.', type: 'array{message: string}')]
    public function __invoke(JoinPlanRequest $request, string $plan, AcceptInvite $acceptInvite): PlanResource
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'POST /plans/{plan}/join');

        // No App\Models and no App\Infrastructure import anywhere in this file, not even an
        // unused one: the arch rule expect('App\Http\Controllers')->not->toUse([...]) fails on the
        // bare import alone. PlanSummary::fromContext() is how every sibling resource's
        // controller gets from a PlanContext to a Resource, and this is that same wrapping, not a
        // special case for the join route.
        return PlanResource::make(
            PlanSummary::fromContext(
                $acceptInvite->execute($user, $plan, $request->string('token')->toString()),
            ),
        );
    }
}
