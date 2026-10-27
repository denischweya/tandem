<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Application\Users\DeleteAccount;
use App\Application\Users\UpdateProfile;
use App\Domain\Shared\TimeZoneId;
use App\Domain\Users\DisplayName;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\UpdateProfileRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\Request;
use Illuminate\Http\Response;

final class MeController
{
    use RequiresAuthenticatedUser;

    // A plain comment, not a docblock: a method docblock on a controller action is
    // harvested by Scramble into the operation's public `summary` (measured: it was,
    // verbatim, until this was changed from /** */ to //) - a leak this project's own
    // comment style distinguishes elsewhere only for FormRequest::rules() array keys and
    // class docblocks. This is the same hazard on a third surface. Client-facing prose for
    // an operation goes in a #[Endpoint] attribute instead, which Scramble reads without a
    // docblock being involved.
    //
    // requireAuthenticatedUser() rather than a local null check: see the trait for why this
    // file names no model class, and why the type still survives.
    public function show(Request $request): UserResource
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'GET /me');

        return UserResource::make($user->load('profile'));
    }

    public function update(UpdateProfileRequest $request, UpdateProfile $updateProfile): UserResource
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'PATCH /me');

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

    // 204, not a body: the client has nothing left to render, and spec §9.3 makes the
    // deletion a soft one with a 30-day grace window - a response describing the deleted
    // account would be a reason for a client to keep showing it.
    public function destroy(Request $request, DeleteAccount $deleteAccount): Response
    {
        $user = $this->requireAuthenticatedUser($request->user(), 'DELETE /me');

        $deleteAccount->execute($user);

        return response()->noContent();
    }
}
