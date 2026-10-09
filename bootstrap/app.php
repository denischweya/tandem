<?php

use App\Application\Auth\InvalidCredentials;
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
        //
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (InvalidCredentials $e) => new JsonResponse(
            ['message' => 'These credentials do not match our records.'],
            401,
        ));
    })->create();
