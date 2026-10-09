<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\LoginController;
use App\Http\Controllers\Api\V1\Auth\LogoutController;
use App\Http\Controllers\Api\V1\Auth\RefreshController;
use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', RegisterController::class)
    ->middleware('throttle:auth-register')
    ->name('auth.register');

// No throttle middleware: spec §8.2 counts login's two limits by failed attempt, not by
// request, and middleware decides before the controller runs - before this request's
// outcome is known. LoginRateLimiter is applied from inside Login::execute() instead; see
// its docblock.
Route::post('/auth/login', LoginController::class)
    ->name('auth.login');

// No throttle: the signature already bounds who can call this usefully, and a rate limit
// keyed on IP would lock out everyone behind a shared NAT who clicks a link at the same time.
Route::post('/auth/verify-email', VerifyEmailController::class)
    ->name('auth.verify-email');

// Unauthenticated, deliberately: the whole point is that the access token has expired. The
// refresh token is the credential, and replay detection is the protection.
Route::post('/auth/refresh', RefreshController::class)->name('auth.refresh');

Route::post('/auth/logout', LogoutController::class)
    ->middleware('auth:sanctum')
    ->name('auth.logout');
