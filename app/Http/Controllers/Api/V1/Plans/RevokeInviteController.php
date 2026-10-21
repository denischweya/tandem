<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\RevokeInvite;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use Dedoc\Scramble\Attributes\Response as ResponseAttribute;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class RevokeInviteController
{
    use RequiresAuthenticatedUser;

    // 204, not a body: the invite list is what a client re-reads afterwards, and a response
    // describing a revoked invite would be a reason to keep showing it. MeController::destroy()
    // is the precedent.
    //
    // The attribute is aliased because this file also needs Illuminate\Http\Response as its
    // return type, and two classes named Response cannot both be imported unaliased.
    //
    // Three declared statuses; Scramble sees the 204 (return type) and the 401 (auth
    // middleware) and neither the render closure nor the Application layer's
    // AuthorizationException. No 422: this route takes no input. No 429: nothing throttles it.
    //
    // Both path parameters are raw strings, never type-hinted models - and {invite}
    // especially: an implicit binding would answer a missing invite with Laravel's own
    // pretty-printed 404 body, a SECOND body for the refusal PlanInviteNotFound already owns,
    // and it would do it without the Plan scoping that makes the cross-Plan case a 404 rather
    // than a revoke.
    #[ResponseAttribute(status: 403, description: 'The signed-in member may not revoke this Plan\'s invites. Revoking requires Owner or Admin, the same capability as inviting.', type: 'array{message: string}')]
    #[ResponseAttribute(status: 404, description: 'Either no Plan with this id is visible to the signed-in user, or no invite with this id belongs to that Plan. The two carry different messages; an invite that belongs to a different Plan is indistinguishable from one that does not exist.', type: 'array{message: string}')]
    #[ResponseAttribute(status: 409, description: 'The Plan has been archived, so it can no longer be written to. Reading its invites still works.', type: 'array{message: string}')]
    public function __invoke(Request $request, string $plan, string $invite, RevokeInvite $revokeInvite): Response
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'DELETE /plans/{plan}/invites/{invite}');

        $revokeInvite->execute($user, $plan, $invite);

        return response()->noContent();
    }
}
