<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Auth\RegisterController;
use App\Http\Controllers\Api\V1\Auth\VerifyEmailController;
use Illuminate\Support\Facades\Route;

Route::post('/auth/register', RegisterController::class)
    ->middleware('throttle:auth-register')
    ->name('auth.register');

// No throttle: the signature already bounds who can call this usefully, and a rate limit
// keyed on IP would lock out everyone behind a shared NAT who clicks a link at the same time.
Route::post('/auth/verify-email', VerifyEmailController::class)
    ->name('auth.verify-email');
