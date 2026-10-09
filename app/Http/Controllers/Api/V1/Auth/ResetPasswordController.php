<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Auth;

use App\Application\Auth\ResetPassword;
use App\Domain\Users\Email;
use App\Http\Requests\Api\V1\Auth\ResetPasswordRequest;
use Illuminate\Http\Response;

final class ResetPasswordController
{
    public function __invoke(ResetPasswordRequest $request, ResetPassword $resetPassword): Response
    {
        $resetPassword->execute(
            $request->string('token')->toString(),
            Email::fromString($request->string('email')->toString()),
            $request->string('password')->toString(),
        );

        return response()->noContent();
    }
}
