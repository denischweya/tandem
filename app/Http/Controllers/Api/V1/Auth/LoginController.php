<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\DeviceDescriptor;
use App\Application\Auth\Login;
use App\Domain\Users\Email;
use App\Http\Requests\Api\V1\Auth\LoginRequest;
use App\Http\Resources\Api\V1\SessionResource;

final class LoginController
{
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
