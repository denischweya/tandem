<?php

use App\Application\Auth\InvalidCredentials;
use App\Application\Auth\InvalidPasswordResetToken;
use App\Application\Auth\InvalidRefreshToken;
use Illuminate\Auth\Middleware\EnsureEmailIsVerified;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: null,
        then: function (): void {
            // Operational endpoints answer at the root: the test asserts /health,
            // not /api/v1/health. Monitoring should not have to know the API version.
            Route::middleware('api')->group(__DIR__.'/../routes/api/v1/ops.php');

            Route::prefix('api/v1')
                ->middleware('api')
                ->group(function (): void {
                    require __DIR__.'/../routes/api/v1/auth.php';
                    require __DIR__.'/../routes/api/v1/me.php';
                    require __DIR__.'/../routes/api/v1/plans.php';
                });
        },
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Registered as an alias so a route can declare `verified`. Laravel's
        // EnsureEmailIsVerified redirects to a named route for web requests; for a JSON
        // request it aborts 403, which is the only path this API takes - every request under
        // api/* is forced to JSON by the shouldRenderJsonWhen callback below.
        $middleware->alias(['verified' => EnsureEmailIsVerified::class]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (InvalidCredentials $e) => new JsonResponse(
            ['message' => 'These credentials do not match our records.'],
            401,
        ));

        // One body for unknown, expired, revoked and replayed - see InvalidRefreshToken.
        // Distinguishing them here would tell an attacker holding a captured token whether
        // their replay was detected, and tells a legitimate client nothing it can act on.
        $exceptions->render(fn (InvalidRefreshToken $e) => new JsonResponse(
            ['message' => 'The refresh token is not valid.'],
            401,
        ));

        // 422, not 401: this is a form submission with a bad field (the token), not a failed
        // authentication attempt. One body for unknown, used, expired and wrong-email - see
        // InvalidPasswordResetToken.
        $exceptions->render(fn (InvalidPasswordResetToken $e) => new JsonResponse([
            'message' => 'This password reset link is no longer valid. Request a new one.',
            'errors' => ['token' => ['This password reset link is no longer valid. Request a new one.']],
        ], 422));
    })->create();
