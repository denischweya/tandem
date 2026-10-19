<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\GetPlan;
use App\Application\Plans\PlanSummary;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Resources\Api\V1\Plans\PlanResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;

final class GetPlanController
{
    use RequiresAuthenticatedUser;

    // A // comment and not a docblock: a controller method docblock is harvested by Scramble
    // into the operation's PUBLISHED summary and description. Measured once the hard way on
    // MeController::show(), which published its own internal note verbatim until the block was
    // changed from /** */ to //.
    //
    // string $plan, never Plan $plan. SubstituteBindings is in the `api` middleware group and
    // sorts before the controller, so an implicit binding would make a missing Plan throw
    // ModelNotFoundException and emit Laravel's own pretty-printed 404 - a second 404 body on
    // this one route, which is exactly what spec §5.1 rejects Route::whereUuid('plan') to
    // avoid. The identifier's shape is validated inside the repository instead, so a non-uuid
    // and an unknown uuid produce the same body.
    //
    // 404 is declared by hand because Scramble infers one only for a path parameter whose
    // schema carries isModelId === true, i.e. for route-model binding, which this route does
    // not use (ErrorResponsesExtension::attachNotFoundException()).
    #[Response(status: 404, description: 'No Plan with this identifier is visible to you. A Plan you are not an active member of is indistinguishable from one that does not exist.', type: 'array{message: string}')]
    public function __invoke(Request $request, string $plan, GetPlan $getPlan): PlanResource
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'GET /plans/{plan}');

        return PlanResource::make(PlanSummary::fromContext($getPlan->execute($user, $plan)));
    }
}
