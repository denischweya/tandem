<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\VerifyEmail;
use App\Http\Requests\Api\V1\Auth\VerifyEmailRequest;
use App\Http\Resources\Api\V1\UserResource;

/**
 * POST, not GET, even though the user arrives from an email link. Mail clients, link
 * scanners and corporate security gateways prefetch GET URLs, which would silently verify an
 * address nobody clicked. The link in the email therefore goes to the web app, which renders
 * a page with a button that POSTs here with the signed query relayed verbatim.
 *
 * The signature itself is checked in VerifyEmailRequest::authorize(), before anything is
 * loaded. The record lookup and the sha1(email) hash check are in the use case, because
 * tests/Architecture forbids a controller from touching App\Models - see
 * VerifyEmail::forSignedPayload().
 */
final class VerifyEmailController
{
    public function __invoke(VerifyEmailRequest $request, VerifyEmail $verifyEmail): UserResource
    {
        $user = $verifyEmail->forSignedPayload(
            $request->string('id')->toString(),
            $request->string('hash')->toString(),
        );

        $verifyEmail->execute($user);

        return UserResource::make($user->refresh());
    }
}
