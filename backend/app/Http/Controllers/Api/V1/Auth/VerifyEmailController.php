<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\VerifyEmail;
use App\Http\Requests\Api\V1\Auth\VerifyEmailRequest;
use App\Http\Resources\Api\V1\UserResource;
use Dedoc\Scramble\Attributes\Endpoint;

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
    // Client-facing, and the only operation in this wave that gets prose, because it is the
    // only one whose correct use is not deducible from its parameters: all four query
    // parameters are one signed unit and must be relayed byte-for-byte from the link.
    // Recomputing or re-encoding any of them invalidates the HMAC and earns a 403 for ever,
    // which is exactly the mistake a client writes once.
    //
    // An #[Endpoint] attribute, not a method docblock: a controller method docblock is the
    // third Scramble harvest position (spec §5.2) and becomes the operation's summary, so
    // using one here would mean this file could never carry a note for the next engineer
    // again. The attribute says the same thing without claiming that position.
    #[Endpoint(
        title: 'Confirm an email address from a signed verification link',
        description: 'Relay all four query parameters verbatim from the link in the verification email, including `expires` and `signature`. They are signed as a unit: altering, re-encoding or recomputing any of them invalidates the signature and the request is refused with 403. There is no request body.',
    )]
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
