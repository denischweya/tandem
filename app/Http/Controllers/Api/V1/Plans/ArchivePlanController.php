<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\ArchivePlan;
use App\Application\Plans\PlanSummary;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Resources\Api\V1\Plans\PlanResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Request;

final class ArchivePlanController
{
    use RequiresAuthenticatedUser;

    // No FormRequest and no body: there is nothing to send. That also means Scramble publishes
    // no 422 for this operation, which is correct - there is no field for one to attach to.
    //
    // There is no unarchive route, and the reason is structural rather than a scope cut: a Plan
    // archived by spec §9.3 branch 3 has no live owner and no active member at all, so an
    // unarchive route authorized to Owner would have no possible caller on exactly the Plans
    // that reach that state.
    #[Response(status: 403, description: 'Only the Plan owner can archive a Plan, and the owner account must have a verified email address.', type: 'array{message: string}')]
    #[Response(status: 404, description: 'No Plan with this identifier is visible to you. A Plan you are not an active member of is indistinguishable from one that does not exist.', type: 'array{message: string}')]
    public function __invoke(Request $request, string $plan, ArchivePlan $archivePlan): PlanResource
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'POST /plans/{plan}/archive');

        return PlanResource::make(PlanSummary::fromContext($archivePlan->execute($user, $plan)));
    }
}
