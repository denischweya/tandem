<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\RefreshSession;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use App\Http\Resources\Api\V1\SessionResource;
use Dedoc\Scramble\Attributes\Response;

final class RefreshController
{
    // The 401 this endpoint exists to pair with. Criterion 11's mechanism is "refresh on
    // 401", and this operation published 200 and 422 only - so the one endpoint a client calls
    // BECAUSE it got a 401 did not say it could answer with one itself. One body for unknown,
    // expired, revoked and replayed (see InvalidRefreshToken), rendered by a closure in
    // bootstrap/app.php that Scramble cannot see.
    #[Response(status: 401, description: 'The refresh token is unknown, expired, revoked or has already been rotated. Sign in again.', type: 'array{message: string}')]
    public function __invoke(RefreshTokenRequest $request, RefreshSession $refresh): SessionResource
    {
        return SessionResource::make(
            $refresh->execute($request->string('refresh_token')->toString()),
        );
    }
}
