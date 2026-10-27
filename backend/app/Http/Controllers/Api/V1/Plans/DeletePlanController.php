<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\DeletePlan;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DeletePlanController
{
    use RequiresAuthenticatedUser;

    // 204, not a body: the Plan and everything hanging off it are gone, so there is nothing for
    // a client to render and nothing for it to keep. MeController::destroy() is the precedent.
    //
    // The attribute is aliased because this file also needs Illuminate\Http\Response as its
    // return type, and two classes named Response cannot both be imported unaliased.
    #[ResponseAttribute(status: 403, description: 'Only the Plan owner can delete a Plan, and the owner account must have a verified email address. An Admin can edit a Plan but not delete it.', type: 'array{message: string}')]
    #[ResponseAttribute(status: 404, description: 'No Plan with this identifier is visible to you. A Plan you are not an active member of is indistinguishable from one that does not exist.', type: 'array{message: string}')]
    public function __invoke(Request $request, string $plan, DeletePlan $deletePlan): Response
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'DELETE /plans/{plan}');

        $deletePlan->execute($user, $plan);

        return response()->noContent();
    }
}
