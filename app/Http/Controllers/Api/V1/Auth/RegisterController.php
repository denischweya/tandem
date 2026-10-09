<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\Register;
use App\Domain\Shared\TimeZoneId;
use App\Domain\Users\DisplayName;
use App\Domain\Users\Email;
use App\Http\Requests\Api\V1\Auth\RegisterRequest;
use App\Http\Resources\Api\V1\UserResource;
use Illuminate\Http\JsonResponse;

final class RegisterController
{
    public function __invoke(RegisterRequest $request, Register $register): JsonResponse
    {
        $user = $register->execute(
            Email::fromString($request->string('email')->toString()),
            DisplayName::fromString($request->string('display_name')->toString()),
            TimeZoneId::fromString($request->string('timezone')->toString()),
            $request->string('locale', 'en')->toString(),
            $request->string('password')->toString(),
        );

        return UserResource::make($user)->response()->setStatusCode(201);
    }
}
