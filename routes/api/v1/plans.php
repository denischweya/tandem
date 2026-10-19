<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Plans\GetPlanController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Deliberately NOT behind `verified`, and spec §7.5 is where the decision is argued: the
    // gate covers every Plan route that acts on another member's behalf or creates state other
    // members see, and it covers no read. If joining were exempt and reading were not, a user
    // who had just joined a Plan on an unverified account would be refused on the Plan they
    // joined a second earlier - worse than gating everything or gating nothing. The precedent
    // for writing the exemption and its reason in the route file is routes/api/v1/me.php's
    // DELETE /me comment.
    Route::get('/plans/{plan}', GetPlanController::class)->name('plans.show');
});
