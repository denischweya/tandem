<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Plans\ArchivePlanController;
use App\Http\Controllers\Api\V1\Plans\CreatePlanController;
use App\Http\Controllers\Api\V1\Plans\DeletePlanController;
use App\Http\Controllers\Api\V1\Plans\GetPlanController;
use App\Http\Controllers\Api\V1\Plans\UpdatePlanController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // `verified` AND throttle:plans-create, and three measured notes about the pair.
    //
    // (1) The order written here is cosmetic. Laravel sorts route middleware by the Kernel's own
    // $middlewarePriority - bootstrap/app.php never calls priority(), and
    // Middleware::getMiddlewarePriority() returns [] for this app, which is falsy, so
    // setMiddlewarePriority() is never called and the Kernel keeps its 11-entry default. The
    // sorted order for this exact list is 0 Authenticate:sanctum, 1 ThrottleRequests,
    // 2 SubstituteBindings, 3 EnsureEmailIsVerified.
    //
    // (2) That order is what makes the plans-create closure able to key on $request->user():
    // auth:sanctum has already run. Do not "override middleware priority to be safe" - an empty
    // or partial list would silently disable the ordering this depends on.
    //
    // (3) Because `verified` sorts LAST and ThrottleRequests::handleRequest() calls hit() before
    // $next($request), an unverified user's 403 still spends a plans-create token: their
    // thirty-first attempt is a 429 rather than a 403. Recorded rather than fixed - the only
    // lever is $middlewarePriority, see (2).
    Route::post('/plans', CreatePlanController::class)
        ->middleware(['verified', 'throttle:plans-create'])
        ->name('plans.store');

    // Deliberately NOT behind `verified`, and spec §7.5 is where the decision is argued: the
    // gate covers every Plan route that acts on another member's behalf or creates state other
    // members see, and it covers no read. If joining were exempt and reading were not, a user
    // who had just joined a Plan on an unverified account would be refused on the Plan they
    // joined a second earlier - worse than gating everything or gating nothing. The precedent
    // for writing the exemption and its reason in the route file is routes/api/v1/me.php's
    // DELETE /me comment.
    Route::get('/plans/{plan}', GetPlanController::class)->name('plans.show');

    // `verified`: PATCH creates state other members see (the edit itself, and a plan.updated row
    // in a feed every member reads), which is the line spec §7.5 draws.
    Route::patch('/plans/{plan}', UpdatePlanController::class)
        ->middleware('verified')
        ->name('plans.update');

    // `verified`: archiving ends the Plan for every member, which is as far from a read as a
    // Plan action gets (spec §7.5).
    Route::post('/plans/{plan}/archive', ArchivePlanController::class)
        ->middleware('verified')
        ->name('plans.archive');

    // `verified`: deleting removes the Plan for every member, and the data is not recoverable.
    Route::delete('/plans/{plan}', DeletePlanController::class)
        ->middleware('verified')
        ->name('plans.destroy');
});
