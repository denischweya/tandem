<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\AddReaction;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\AddReactionRequest;
use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use Illuminate\Http\Response;

final class AddReactionController
{
    use RequiresAuthenticatedUser;

    // 204, not a body: the message list is what a client re-reads to see the reaction
    // summarised, matching DeleteMessageController's own reasoning for the same shape.
    //
    // The attribute is aliased because this file also needs Illuminate\Http\Response as its
    // return type - the same device DeleteMessageController uses.
    //
    // Three declared statuses, for the same reason SendMessageController declares its own
    // three (this route carries the identical `verified` + `throttle:` middleware pair, and
    // AddReaction has no NotMessageAuthor-shaped check of its own competing for the 403 slot,
    // unlike EditMessageController/DeleteMessageController): `verified` and `throttle:` are
    // both route middleware, invisible to Scramble's ErrorResponsesExtension, and the 404 is a
    // bootstrap/app.php render() closure Scramble cannot see either. The 401 and the 422 ARE
    // inferred (auth:sanctum, and AddReactionRequest's rules), so neither is declared here.
    #[ResponseAttribute(status: 403, description: 'Your email address is not verified. Reacting to a message is one of the actions that needs a verified address, because every other member of the Plan will see it.', type: 'array{message: string}')]
    #[ResponseAttribute(status: 404, description: 'Either no Plan with this id is visible to the signed-in user, or no message with this id belongs to that Plan. The two carry different messages; a message that belongs to a different Plan is indistinguishable from one that does not exist.', type: 'array{message: string}')]
    #[ResponseAttribute(status: 429, description: 'Too many reactions from this account: 60 per minute. Carries Retry-After.', type: 'array{message: string}')]
    public function __invoke(AddReactionRequest $request, string $plan, string $message, AddReaction $addReaction): Response
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'POST /plans/{plan}/messages/{message}/reactions');

        $addReaction->execute($user, $plan, $message, $request->string('reaction')->toString());

        return response()->noContent();
    }
}
