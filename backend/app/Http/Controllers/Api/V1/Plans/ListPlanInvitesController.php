<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\ListPlanInvites;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Resources\Api\V1\Plans\PlanInviteResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListPlanInvitesController
{
    use RequiresAuthenticatedUser;

    // Two declared statuses. Scramble reads the 200 off the return type and the 401 off
    // auth:sanctum; it reads neither an exception-render closure (the 404) nor an
    // AuthorizationException thrown from the Application layer (the 403), because this
    // codebase's controllers extend nothing and $this->authorize() is unavailable. There is no
    // 422: this route takes no input, and no 429: nothing throttles an authenticated read
    // behind a role check.
    //
    // No ->additional(['meta' => ...]): this list is NOT paginated, so there is no cursor and
    // no meta envelope to publish. A meta key added here would have to be a lie, because
    // nothing is holding a page.
    //
    // No FormRequest, because there is no body and no query parameter. {plan} is a raw string,
    // never a type-hinted Plan: SubstituteBindings sorts before the controller, so an implicit
    // binding would make a missing Plan throw ModelNotFoundException and emit Laravel's own
    // pretty-printed 404 body - a second 404 body on a route that already has two deliberate
    // ones.
    #[Response(status: 403, description: 'The signed-in member may not read this Plan\'s invites. Listing invites requires Owner or Admin, because the list carries the addresses the inviter typed.', type: 'array{message: string}')]
    #[Response(status: 404, description: 'No Plan with this id is visible to the signed-in user. The same body is returned for a Plan that does not exist and for one the user is not an active member of.', type: 'array{message: string}')]
    public function __invoke(Request $request, string $plan, ListPlanInvites $listPlanInvites): AnonymousResourceCollection
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'GET /plans/{plan}/invites');

        return PlanInviteResource::collection($listPlanInvites->execute($user, $plan));
    }
}
