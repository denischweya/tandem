<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\PreviewInvite;
use App\Http\Resources\Api\V1\Plans\InvitePreviewResource;
use Dedoc\Scramble\Attributes\Response;

final class PreviewInviteController
{
    // A plain comment, not a docblock: a method docblock on a controller action is harvested by
    // Scramble into the operation's public `summary`. Client-facing prose for an operation goes
    // in a #[Endpoint] attribute instead.
    //
    // Both statuses are DECLARED because Scramble can infer neither: the 404 comes from an
    // exception-render closure in bootstrap/app.php, which no static analysis reaches, and the
    // 429 comes from route-level throttle: middleware, which it does not read for responses.
    //
    // type: 'array{message: string}' rather than a bare string, because that is the body both
    // paths actually return. Without `type` the attribute publishes `type: string`, which is a
    // new false statement in place of a missing one.
    #[Response(status: 404, description: 'The invite link cannot be used: it is unknown, malformed, expired, revoked, already accepted, or for a Plan that is no longer active. One body for every case, so a captured link reveals nothing about which.', type: 'array{message: string}')]
    #[Response(status: 429, description: 'Too many invite lookups from this IP address: 60 per hour. Carries Retry-After.', type: 'array{message: string}')]
    public function __invoke(string $token, PreviewInvite $previewInvite): InvitePreviewResource
    {
        // No FormRequest, because there is no request body and no field: the token is a path
        // parameter. Laravel injects a route parameter by name and resolves the use case from the
        // container, in either order.
        //
        // No App\Models and no App\Infrastructure import anywhere in this file, not even an
        // unused one: the arch rule expect('App\Http\Controllers')->not->toUse([...]) fails on
        // the bare import alone.
        return InvitePreviewResource::make($previewInvite->execute($token));
    }
}
