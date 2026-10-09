<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\Logout;
use App\Http\Requests\Api\V1\Auth\RefreshTokenRequest;
use Illuminate\Http\Response;
use RuntimeException;

final class LogoutController
{
    public function __invoke(RefreshTokenRequest $request, Logout $logout): Response
    {
        $user = $request->user();

        // A real null check, and no `use App\Models\User` anywhere in this file. Both halves
        // are load-bearing:
        //
        // The check, because Logout::execute() takes a User and $request->user() is nullable.
        // A null here means auth:sanctum was removed from this route in
        // routes/api/v1/auth.php, which must fail loudly rather than be asserted away by a
        // /** @var User */ that stops being true the moment someone makes that edit.
        //
        // The absent import, because spec §6 forbids a controller touching Eloquent or
        // App\Models and the architecture test enforces it - naming App\Models\User here to
        // type this variable fails "controllers touch no Eloquent and no persistence"
        // (measured: it did). The type needs no help: larastan resolves Request::user()
        // from config('auth.providers.users.model'), so $user is already App\Models\User|null
        // to PHPStan without the class being named, and the narrowed call below checks clean
        // at level 8.
        if ($user === null) {
            throw new RuntimeException(
                'POST /auth/logout was reached without an authenticated user, which means '
                .'auth:sanctum is no longer on this route.'
            );
        }

        $logout->execute($user, $request->string('refresh_token')->toString());

        return response()->noContent();
    }
}
