<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\DeviceDescriptor;
use App\Application\Auth\Login;
use App\Domain\Users\Email;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\SessionResource;
use Dedoc\Scramble\Attributes\Response;

final class LoginController
{
    // Declared, because Scramble cannot infer either of these. The 401 comes from an
    // exception-render closure in bootstrap/app.php, which no static analysis reaches; the 429
    // comes from App\Infrastructure\Auth\LoginRateLimiter, inside the use case, because spec
    // §8.2 counts login's limits by FAILED ATTEMPT and route middleware cannot see an outcome.
    // Both are asserted by the feature suite and were absent from the contract, which matters
    // most for the 401: spec §17 criterion 11's whole mechanism is "refresh on 401", and a
    // client generated from this document had no 401 to handle on either endpoint that
    // produces one.
    //
    // array{message: string} rather than a bare string: that is the body both paths actually
    // return. Without `type` the attribute publishes `type: string`, which would be a new
    // false statement in place of a missing one.
    //
    // The 429's numbers are spec §8.2's, restated for a client without the citation: the
    // description below is PUBLISHED, and a reference to an internal document is of no use to
    // anyone reading the contract. Same for the other two throttled operations.
    #[Response(status: 401, description: 'The email and password do not match an account.', type: 'array{message: string}')]
    #[Response(status: 429, description: 'Too many failed sign-in attempts: 10 per 15 minutes per IP address, 5 per 15 minutes per email address. Only failed attempts are counted, so a successful sign-in costs nothing. Carries Retry-After.', type: 'array{message: string}')]
    public function __invoke(LoginRequest $request, Login $login): SessionResource
    {
        $installId = $request->string('install_id')->toString();

        $session = $login->execute(
            Email::fromString($request->string('email')->toString()),
            $request->string('password')->toString(),
            $installId === '' ? null : new DeviceDescriptor($installId, $request->string('platform', 'web')->toString()),
            $request->ip() ?? 'unknown',
        );

        return SessionResource::make($session);
    }
}
