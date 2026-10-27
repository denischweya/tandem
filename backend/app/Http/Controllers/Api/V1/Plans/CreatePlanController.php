<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\CreatePlan;
use App\Application\Plans\PlanSummary;
use App\Domain\Shared\TimeZoneId;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\CreatePlanRequest;
use App\Http\Resources\Api\V1\Plans\PlanResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class CreatePlanController
{
    use RequiresAuthenticatedUser;

    // Both statuses are invisible to Scramble, which is why they are attributes rather than
    // something it infers: `verified` is route middleware and throttle: is route middleware,
    // and ErrorResponsesExtension reads neither. type: 'array{message: string}' rather than a
    // bare string, because that is the body both paths return - without `type` the attribute
    // publishes `type: string`, a new false statement in place of a missing one.
    #[Response(status: 403, description: 'Your email address is not verified. Creating a Plan is one of the actions that needs a verified address, because everyone you invite will see it.', type: 'array{message: string}')]
    #[Response(status: 429, description: 'Too many Plans created from this account: 30 per hour. Carries Retry-After.', type: 'array{message: string}')]
    public function __invoke(CreatePlanRequest $request, CreatePlan $createPlan): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'POST /plans');

        // $request->string()/->has(), not ->validated(): the INPUT bag, not the validated
        // subset. Safe here only because CreatePlanRequest::rules() leaves no reachable
        // non-scalar for either field to read: an empty 'description' becomes null through
        // the global ConvertEmptyStringsToNull before 'sometimes'+'required' 422s it, and
        // 'string' rejects an array before this line ever runs. That safety is entirely a
        // property of the rule set above, not of anything here - a later relaxation of
        // rules() (an array rule added to either field, say) would silently remove it.
        $context = $createPlan->execute(
            $user,
            $request->string('title')->toString(),
            $request->has('description') ? $request->string('description')->toString() : null,
            TimeZoneId::fromString($request->string('timezone')->toString()),
        );

        return PlanResource::make(PlanSummary::fromContext($context))->response()->setStatusCode(201);
    }
}
