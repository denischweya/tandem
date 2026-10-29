<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\RemoveReaction;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class RemoveReactionController
{
    use RequiresAuthenticatedUser;

    // No AddReactionRequest-shaped sibling: removeReaction() takes no body and $reaction
    // arrives as a path segment, not a validated field - an unrecognised value is simply a
    // reaction nothing matches, which RemoveReaction::execute() and removeReaction() both
    // treat as the same no-op success a recognised-but-absent one gets (see
    // PlanMessageRepository::removeReaction()'s own docblock).
    //
    // The attribute is aliased because this file also needs Illuminate\Http\Response as its
    // return type - the same device DeleteMessageController uses.
    //
    // Two declared statuses: this route carries `verified` and no NotMessageAuthor-shaped
    // check of its own to compete for the 403 slot (unlike EditMessageController/
    // DeleteMessageController, which document NotMessageAuthor there instead), so the verified
    // gate's own 403 is documented here the same way SendMessageController and
    // AddReactionController document theirs. No 429: this route carries no throttle: (see
    // AppServiceProvider's own comment beside the 'chat-react' limiter for why removing one's
    // own reaction is not rate-limited).
    #[ResponseAttribute(status: 403, description: 'Your email address is not verified. Removing your reaction from a message is one of the actions that needs a verified address, because every other member of the Plan will see it.', type: 'array{message: string}')]
    #[ResponseAttribute(status: 404, description: 'Either no Plan with this id is visible to the signed-in user, or no message with this id belongs to that Plan. The two carry different messages; a message that belongs to a different Plan is indistinguishable from one that does not exist.', type: 'array{message: string}')]
    public function __invoke(Request $request, string $plan, string $message, string $reaction, RemoveReaction $removeReaction): Response
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'DELETE /plans/{plan}/messages/{message}/reactions/{reaction}');

        $removeReaction->execute($user, $plan, $message, $reaction);

        return response()->noContent();
    }
}
