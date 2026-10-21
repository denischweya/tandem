<?php

use App\Application\Auth\InvalidCredentials;
use App\Application\Auth\InvalidPasswordResetToken;
use App\Application\Auth\InvalidRefreshToken;
use App\Application\Plans\ActorEmailNotVerified;
use App\Application\Plans\InviteNotUsable;
use App\Application\Plans\OwnerCannotLeave;
use App\Application\Plans\OwnerInviteNotAcceptable;
use App\Application\Plans\PlanMemberNotFound;
use App\Application\Plans\PlanNotEditable;
use App\Application\Plans\PlanNotInvitable;
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
                    require __DIR__.'/../routes/api/v1/invites.php';
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
            // An owner or admin naming a {user} who is not an active member of the Plan - a
            // stale client, a removed member, a mistyped id. Expected control flow on the same
            // argument as the entries above, and the only one of the three Plan exceptions that
            // is reachable by a caller who HAS already proved they can see the Plan.
            PlanMemberNotFound::class,
            // An owner tapping "leave" on their own Plan - the one refusal in this wave with an
            // actionable body, which is itself evidence that users try it. Expected control flow
            // on the same argument as the entries above, and reporting it at ERROR would bury the
            // Log::warning() security lines on the same channel.
            OwnerCannotLeave::class,
            // An unverified actor removing another member. Exactly as expected as every
            // EnsureEmailIsVerified refusal on the gated routes, which Laravel never reports
            // either - this one differs only in living in the Application layer, so it would
            // otherwise be the one `verified` refusal in the API that logged at ERROR.
            ActorEmailNotVerified::class,
            // Every stale, revoked or mistyped invite link - unknown, malformed, expired,
            // revoked, already accepted, or for a Plan that is no longer active - reaches this
            // at a rate that scales with traffic, not with bugs. Listed alongside the other
            // expected control-flow refusals above, for the same reason each of them is.
            InviteNotUsable::class,
            // An owner or admin attempting to invite someone to a Plan whose visibility is
            // private. Expected control flow on the same argument as the entries above, and
            // its volume scales with traffic rather than with bugs.
            PlanNotInvitable::class,
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

        // A precise message, unlike PlanNotVisible's deliberately vague one, and the ordering is
        // what makes it safe: findVisibleTo() runs first in every use case, so this is only ever
        // reachable by a caller who has already proved they can see the Plan and holds the role
        // for the action. There is nothing left to disclose at that point, and "That person is
        // not a member of this Plan" says nothing about whether the id names a real user. A
        // single vague body for both would have been safe too, and worse: an owner who can
        // plainly see their own Plan being told "Plan not found" is the kind of refusal blueprint
        // §135 exists to prevent. Encoded compactly, like every closure above.
        $exceptions->render(fn (PlanMemberNotFound $e) => new JsonResponse(
            ['message' => 'That person is not a member of this Plan.'],
            404,
        ));

        // 409, not 403 and not 404: the refusal is about the Plan's STATE, so the owner of an
        // archived Plan is refused exactly as a member is, and a 404 would contradict the 200
        // the same path still answers for GET. Encoded compactly like the closures above.
        $exceptions->render(fn (PlanNotEditable $e) => new JsonResponse(
            ['message' => 'This Plan has been archived.'],
            409,
        ));

        // 403 with its own body, not Laravel's generic one, because this is the one refusal in
        // the wave that has an exit - see OwnerCannotLeave. A render() closure and not a bare
        // AuthorizationException: that class overwrites the message with its own default,
        // "This action is unauthorized.", and the actionable sentence is the whole point.
        // Encoded compactly like every closure above.
        $exceptions->render(fn (OwnerCannotLeave $e) => new JsonResponse(
            ['message' => 'Transfer ownership or archive this Plan before leaving it.'],
            403,
        ));

        // The same status and the same message EnsureEmailIsVerified produces, so that the one
        // route whose `verified` gate lives in the Application layer (spec §8.3's DELETE, which
        // is two use cases on one URI) is indistinguishable from the routes where the middleware
        // does it. The only difference is whitespace - the middleware's abort(403, ...) goes
        // through Handler::prepareJsonResponse(), which encodes with JSON_PRETTY_PRINT, while
        // this closure encodes compactly - and no JSON client and no test assertion in this
        // suite can see it, because both decode.
        $exceptions->render(fn (ActorEmailNotVerified $e) => new JsonResponse(
            ['message' => 'Your email address is not verified.'],
            403,
        ));

        // One body for an unknown token, a malformed one, an expired one, a revoked one, one
        // already accepted, one issued for a different Plan, and one whose Plan is no longer
        // active - see InviteNotUsable. 404 rather than 422 because the accept-invite page has
        // no field to attach an error to. Encoded compactly, like every closure above: Laravel's
        // own handler pretty-prints, so a second 404 body arriving from there would differ from
        // this one in whitespace as well as in text.
        $exceptions->render(fn (InviteNotUsable $e) => new JsonResponse(
            ['message' => 'This invite link is no longer valid. Ask whoever invited you for a new one.'],
            404,
        ));

        // 409, not 403: the refusal is about the Plan's STATE, not about who is asking - the
        // owner of a private Plan is refused exactly as an admin is. Its own message rather than
        // PlanNotEditable's, because "archived" and "private" are different facts and a client
        // that cannot tell them apart cannot say anything useful. No remedy in the sentence,
        // because `visibility` is not writable anywhere in Cycle 1. Encoded compactly, like
        // every closure above.
        $exceptions->render(fn (PlanNotInvitable $e) => new JsonResponse(
            ['message' => 'This Plan is private, so nobody else can be invited to it.'],
            409,
        ));

        // 409, like PlanNotInvitable and PlanNotEditable above - the request is well-formed and
        // the caller did nothing wrong, so this is a state conflict rather than a validation
        // failure. UNLIKE every exception above, this class carries NO dontReport() entry: a
        // non-member probing a Plan id or a dead invite link is expected traffic at a rate that
        // scales with traffic, but a plan_invites row carrying role = 'owner' is a data-integrity
        // signal - see OwnerInviteNotAcceptable's own docblock for the human ruling behind this.
        // Leaving it reportable is what makes the accompanying Log::warning() in AcceptInvite
        // mean something: this is the one refusal in the wave meant to be noticed, not absorbed.
        $exceptions->render(fn (OwnerInviteNotAcceptable $e) => new JsonResponse(
            ['message' => 'This invite cannot be accepted because it would make you a second owner of this Plan.'],
            409,
        ));
    })->create();
