<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Plans\AddReactionController;
use App\Http\Controllers\Api\V1\Plans\DeleteMessageController;
use App\Http\Controllers\Api\V1\Plans\EditMessageController;
use App\Http\Controllers\Api\V1\Plans\ListPlanMessagesController;
use App\Http\Controllers\Api\V1\Plans\RemoveReactionController;
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

    Route::patch('/plans/{plan}/messages/{message}', EditMessageController::class)
        ->middleware('verified')
        ->name('plans.messages.update');

    Route::delete('/plans/{plan}/messages/{message}', DeleteMessageController::class)
        ->middleware('verified')
        ->name('plans.messages.destroy');

    Route::post('/plans/{plan}/messages/{message}/reactions', AddReactionController::class)
        ->middleware(['verified', 'throttle:chat-react'])
        ->name('plans.messages.reactions.store');

    Route::delete('/plans/{plan}/messages/{message}/reactions/{reaction}', RemoveReactionController::class)
        ->middleware('verified')
        ->name('plans.messages.reactions.destroy');
});
