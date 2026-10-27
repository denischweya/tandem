<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\PlanSummary;
use App\Application\Plans\UpdatePlan;
use App\Domain\Shared\TimeZoneId;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\UpdatePlanRequest;
use App\Http\Resources\Api\V1\Plans\PlanResource;
use Dedoc\Scramble\Attributes\Response;

final class UpdatePlanController
{
    use RequiresAuthenticatedUser;

    // Three statuses, none of them inferable. The 403 is raised by a hand-rolled
    // AuthorizationException in UpdatePlan and by the `verified` middleware; the 404 by a
    // render() closure on PlanNotVisible; the 409 by a render() closure on PlanNotEditable.
    // Scramble reads none of those: it infers 403 only from can: middleware or a FormRequest
    // authorize(), and 404 only from route-model binding.
    #[Response(status: 403, description: 'You are not allowed to edit this Plan: your role is Member, or your email address is not verified.', type: 'array{message: string}')]
    #[Response(status: 404, description: 'No Plan with this identifier is visible to you. A Plan you are not an active member of is indistinguishable from one that does not exist.', type: 'array{message: string}')]
    #[Response(status: 409, description: 'This Plan has been archived, so it can no longer be edited. Archived Plans stay readable, and can still be deleted by their owner.', type: 'array{message: string}')]
    public function __invoke(UpdatePlanRequest $request, string $plan, UpdatePlan $updatePlan): PlanResource
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'PATCH /plans/{plan}');

        $context = $updatePlan->execute(
            $user,
            $plan,
            $request->has('title') ? $request->string('title')->toString() : null,
            $request->has('description') ? $request->string('description')->toString() : null,
            $request->has('timezone') ? TimeZoneId::fromString($request->string('timezone')->toString()) : null,
        );

        return PlanResource::make(PlanSummary::fromContext($context));
    }
}
