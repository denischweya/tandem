<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\SendMessage;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\SendMessageRequest;
use App\Http\Resources\Api\V1\Plans\PlanMessageResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class SendMessageController
{
    use RequiresAuthenticatedUser;

    // Three declared statuses, for the same reason CreatePlanController declares its own two:
    // `verified` and `throttle:` are both route middleware, and Scramble's ErrorResponsesExtension
    // reads neither. The 404 is declared for the same reason PlanActivityController's is - Scramble
    // cannot see bootstrap/app.php's exception-render closure. The 401 and the 422 ARE inferred
    // (auth:sanctum, and SendMessageRequest's rules), so they are not declared here.
    #[Response(status: 403, description: 'Your email address is not verified. Sending a message is one of the actions that needs a verified address, because every other member of the Plan will see it.', type: 'array{message: string}')]
    #[Response(status: 404, description: 'No Plan with this id is visible to the signed-in user. A Plan that does not exist and a Plan the user is not an active member of answer identically.', type: 'array{message: string}')]
    #[Response(status: 429, description: 'Too many messages sent from this account: 30 per minute. Carries Retry-After.', type: 'array{message: string}')]
    public function __invoke(SendMessageRequest $request, string $plan, SendMessage $sendMessage): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'POST /plans/{plan}/messages');

        $message = $sendMessage->execute($user, $plan, $request->string('body')->toString());

        return PlanMessageResource::make($message)->response()->setStatusCode(201);
    }
}
