<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Plans\ListPlanMessagesController;
use App\Http\Controllers\Api\V1\Plans\SendMessageController;
use Illuminate\Support\Facades\Route;

// Its own file, for the same reason invites.php gives for being one: messages are plan-scoped
// but conceptually their own surface.
Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/plans/{plan}/messages', ListPlanMessagesController::class)
        ->name('plans.messages.index');

    Route::post('/plans/{plan}/messages', SendMessageController::class)
        ->middleware(['verified', 'throttle:chat-send'])
        ->name('plans.messages.store');
});
