<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\Logout;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use Illuminate\Http\Response;

final class LogoutController
{
    use RequiresAuthenticatedUser;

    public function __invoke(RefreshTokenRequest $request, Logout $logout): Response
    {
        // The null check and the absence of a `use App\Models\User` are both load-bearing;
        // the trait records why, and holds the one copy of both.
        $user = $this->requireAuthenticatedUser($request->user(), 'POST /auth/logout');

        $logout->execute($user, $request->string('refresh_token')->toString());

        return response()->noContent();
    }
}
