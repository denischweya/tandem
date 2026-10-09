<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\RefreshSession;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use App\Http\Resources\Api\V1\SessionResource;

final class RefreshController
{
    public function __invoke(RefreshTokenRequest $request, RefreshSession $refresh): SessionResource
    {
        return SessionResource::make(
            $refresh->execute($request->string('refresh_token')->toString()),
        );
    }
}
