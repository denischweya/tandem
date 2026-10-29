<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\DeleteMessage;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class DeleteMessageController
{
    use RequiresAuthenticatedUser;

    // 204, not a body: the message list is what a client re-reads afterwards, matching
    // RevokeInviteController's own reasoning for the same shape.
    //
    // The attribute is aliased because this file also needs Illuminate\Http\Response as its
    // return type, and two classes named Response cannot both be imported unaliased - the
    // same device RevokeInviteController and PlanMemberController use.
    #[ResponseAttribute(status: 403, description: 'The signed-in user is a member of this Plan but is not this message\'s author. Only the author may delete a message.', type: 'array{message: string}')]
    #[ResponseAttribute(status: 404, description: 'Either no Plan with this id is visible to the signed-in user, or no message with this id belongs to that Plan. The two carry different messages; a message that belongs to a different Plan is indistinguishable from one that does not exist.', type: 'array{message: string}')]
    public function __invoke(Request $request, string $plan, string $message, DeleteMessage $deleteMessage): Response
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'DELETE /plans/{plan}/messages/{message}');

        $deleteMessage->execute($user, $plan, $message);

        return response()->noContent();
    }
}
