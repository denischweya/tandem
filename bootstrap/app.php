<?php

use App\Application\Auth\InvalidCredentials;
use App\Application\Auth\InvalidPasswordResetToken;
use App\Application\Auth\InvalidRefreshToken;
use App\Application\Plans\PlanNotEditable;
use App\Application\Plans\PlanNotVisible;
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

        // Expected control flow on a public auth surface, not application errors: a wrong
        // password, a stale refresh token and a dead password-reset link all happen at a
        // rate that scales with traffic, not with bugs. Left reportable, every one of them
        // was logged at ERROR level on the same channel this wave's Log::warning() security
        // lines (replay detection, exhausted chain walks, ...) now use - burying the signal
        // under noise that outscales it. tests/Feature/Auth/RefreshChainTest.php had to
        // filter captured logs down to the `security.` prefix to work around exactly this.
        // The render() handlers below are untouched: dontReport() only stops the log write,
        // never the HTTP response.
        $exceptions->dontReport([
            InvalidCredentials::class,
            InvalidRefreshToken::class,
            InvalidPasswordResetToken::class,
            // A non-member probing a Plan id, and a client acting on a Plan it can no longer
            // see, are expected control flow on an authenticated surface: the volume scales
            // with traffic, not with bugs, and reporting them at ERROR drowns the Log::warning()
            // security lines on the same channel.
            PlanNotVisible::class,
            // A client acting on a Plan that was archived while its screen was open. Expected
            // control flow, and its volume scales with traffic rather than with bugs.
            PlanNotEditable::class,
        ]);

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

        // One body for a malformed {plan}, an unknown Plan, a non-canonical-case (uppercase)
        // spelling of a well-formed uuid, an actor with no plan_members row, and an actor whose
        // row's status does not grant access - see PlanNotVisible. 404 and not 403 because a
        // 403 confirms the Plan exists, and that confirmation is available to anyone with a
        // guessed id (spec §8.1). Encoded compactly, like the three closures above: Laravel's
        // own handler pretty-prints, so a 404 arriving from the router instead of from here
        // would differ in whitespace as well as in text.
        $exceptions->render(fn (PlanNotVisible $e) => new JsonResponse(
            ['message' => 'Plan not found.'],
            404,
        ));

        // 409, not 403 and not 404: the refusal is about the Plan's STATE, so the owner of an
        // archived Plan is refused exactly as a member is, and a 404 would contradict the 200
        // the same path still answers for GET. Encoded compactly like the closures above.
        $exceptions->render(fn (PlanNotEditable $e) => new JsonResponse(
            ['message' => 'This Plan has been archived.'],
            409,
        ));
    })->create();
