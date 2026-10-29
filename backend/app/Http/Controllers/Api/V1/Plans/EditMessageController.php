<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\EditMessage;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\EditMessageRequest;
use App\Http\Resources\Api\V1\Plans\PlanMessageResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class EditMessageController
{
    use RequiresAuthenticatedUser;

    // Two declared statuses, matching SendMessageController's own reasoning: `verified` is
    // route middleware Scramble's ErrorResponsesExtension does not read, and the 403/404 below
    // are both bootstrap/app.php render() closures Scramble cannot see either. The 401 and the
    // 422 ARE inferred (auth:sanctum, and EditMessageRequest's rules), so neither is declared
    // here.
    #[Response(status: 403, description: 'The signed-in user is a member of this Plan but is not this message\'s author. Only the author may edit a message.', type: 'array{message: string}')]
    #[Response(status: 404, description: 'Either no Plan with this id is visible to the signed-in user, or no message with this id belongs to that Plan. The two carry different messages; a message that belongs to a different Plan is indistinguishable from one that does not exist.', type: 'array{message: string}')]
    public function __invoke(EditMessageRequest $request, string $plan, string $message, EditMessage $editMessage): JsonResponse
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'PATCH /plans/{plan}/messages/{message}');

        $edited = $editMessage->execute($user, $plan, $message, $request->string('body')->toString());

        return PlanMessageResource::make($edited)->response();
    }
}
