<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\RequestPasswordReset;
use App\Domain\Users\Email;
use App\Http\Requests\Api\V1\Auth\ForgotPasswordRequest;
use Illuminate\Http\Response;

/**
 * Always 202, whether or not the address exists - see RequestPasswordReset. The request's
 * own ValidEmail rule guarantees Email::fromString() below never sees an address that would
 * fail it; see that rule's docblock for the gap between it and Laravel's 'email:rfc' that
 * made this call a reachable 500 before ValidEmail existed.
 */
final class ForgotPasswordController
{
    public function __invoke(ForgotPasswordRequest $request, RequestPasswordReset $requestPasswordReset): Response
    {
        $requestPasswordReset->execute(Email::fromString($request->string('email')->toString()));

        return response()->noContent(202);
    }
}
