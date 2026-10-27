<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Plans\PreviewInviteController;
use Illuminate\Support\Facades\Route;

// Its own file, because this is the only Wave 2 route outside /plans: an invite link carries a
// TOKEN AND NO PLAN ID, so a recipient opening one cannot be shown what they were invited to by
// any route nested under /plans/{plan}, and a brand-new user must be able to see the Plan before
// they have an account. The join route stays POST /plans/{plan}/join, with the Plan id taken
// from this response.
//
// Unauthenticated, deliberately. Scramble derives security: [] from the absent auth middleware
// via MiddlewareAuthSecurityStrategy, and OpenApiContractTest asserts that correspondence in
// both directions off the live route table, so this needs no further declaration.
//
// Throttled, and it is the only Wave 2 route that needs a limit nothing else already provides:
// it is the only unauthenticated read in the API that touches Plan data. See the invite-lookup
// limiter in AppServiceProvider for why 60/hour/IP and why IP.
//
// NO ->where('token', '[0-9a-f]{64}') pattern: a router miss renders with Laravel's own body,
// which is pretty-printed AND leaks the request path, so one route would answer two different
// 404s depending on whether the token merely LOOKED well formed - and the whole point is that
// the refusals are indistinguishable. A malformed token is a miss on a varchar column, not a
// database error, so it reaches InviteNotUsable like every other unusable token.
Route::get('/invites/{token}', PreviewInviteController::class)
    ->middleware('throttle:invite-lookup')
    ->name('invites.preview');
