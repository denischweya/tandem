<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\MeController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    Route::get('/me', [MeController::class, 'show'])->name('me.show');
    Route::patch('/me', [MeController::class, 'update'])->name('me.update');

    // Deliberately not behind the `verified` middleware the way a Plan route is: App Store
    // Review Guideline 5.1.1(v) requires in-app account deletion for any app that offers
    // account creation, and an account whose email was never verified is exactly the one most
    // likely to want deleting. Gating this on verification would make the guideline
    // unsatisfiable for the accounts it matters most for.
    Route::delete('/me', [MeController::class, 'destroy'])->name('me.destroy');
});
