<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Users\DeleteAccount;
use App\Application\Users\UpdateProfile;
use App\Domain\Shared\TimeZoneId;
use App\Domain\Users\DisplayName;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use RuntimeException;

final class MeController
{
    // A real null check, and no `use App\Models\User` anywhere in this file - see
    // LogoutController for the full account of why: naming App\Models\User here to type
    // $request->user() fails "controllers touch no Eloquent and no persistence" (spec §6),
    // and larastan already resolves Request::user() to App\Models\User|null from
    // config('auth.providers.users.model') without the class being named, so the narrowed
    // call below checks clean at level 8. A null here means auth:sanctum was removed from
    // this route in routes/api/v1/me.php, which must fail loudly rather than be asserted
    // away.
    //
    // A plain comment, not a docblock: a method docblock on a controller action is
    // harvested by Scramble into the operation's public `summary` (measured: it was,
    // verbatim, until this was changed from /** */ to //) - a leak this project's own
    // comment style distinguishes elsewhere only for FormRequest::rules() array keys and
    // class docblocks. This is the same hazard on a third surface.
    public function show(Request $request): UserResource
    {
        $user = $request->user();

        if ($user === null) {
            throw new RuntimeException(
                'GET /me was reached without an authenticated user, which means '
                .'auth:sanctum is no longer on this route.'
            );
        }

        return UserResource::make($user->load('profile'));
    }

    // See show() above for why this null check exists and names no model class.
    public function update(UpdateProfileRequest $request, UpdateProfile $updateProfile): UserResource
    {
        $user = $request->user();

        if ($user === null) {
            throw new RuntimeException(
                'PATCH /me was reached without an authenticated user, which means '
                .'auth:sanctum is no longer on this route.'
            );
        }

        $displayName = $request->has('display_name')
            ? DisplayName::fromString($request->string('display_name')->toString())
            : null;

        $timezone = $request->has('timezone')
            ? TimeZoneId::fromString($request->string('timezone')->toString())
            : null;

        return UserResource::make(
            $updateProfile->execute(
                $user,
                $displayName,
                $timezone,
                $request->has('locale') ? $request->string('locale')->toString() : null,
            )->load('profile'),
        );
    }

    // See show() above for why this null check exists and names no model class.
    //
    // 204, not a body: the client has nothing left to render, and spec §9.3 makes the
    // deletion a soft one with a 30-day grace window - a response describing the deleted
    // account would be a reason for a client to keep showing it.
    public function destroy(Request $request, DeleteAccount $deleteAccount): Response
    {
        $user = $request->user();

        if ($user === null) {
            throw new RuntimeException(
                'DELETE /me was reached without an authenticated user, which means '
                .'auth:sanctum is no longer on this route.'
            );
        }

        $deleteAccount->execute($user);

        return response()->noContent();
    }
}
