<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [MeController::class, 'show'])->name('me.show');
    Route::patch('/me', [MeController::class, 'update'])->name('me.update');
});
