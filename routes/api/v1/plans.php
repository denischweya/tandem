<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\Plans\ArchivePlanController;
use App\Http\Controllers\Api\V1\Plans\CreatePlanController;
use App\Http\Controllers\Api\V1\Plans\DeletePlanController;
use App\Http\Controllers\Api\V1\Plans\GetPlanController;
use App\Http\Controllers\Api\V1\Plans\ListPlansController;
use App\Http\Controllers\Api\V1\Plans\PlanMemberController;
use App\Http\Controllers\Api\V1\Plans\UpdatePlanController;
use Illuminate\Support\Facades\Route;

Route::middleware('auth:sanctum')->group(function (): void {
    // Not gated by `verified` (spec §7.5 gates no read), and not throttled: an authenticated
    // read of one's own membership list is not a surface spec §13 limits.
    Route::get('/plans', ListPlansController::class)->name('plans.index');

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

    // Not gated by `verified` (spec §7.5 gates no read - see VerifiedGateTest's $spec75 map,
    // 'plans.members.index' => false), and not throttled: §13.4 limits plans-create,
    // plan-invites and invite-lookup only, and a read scoped to one Plan's membership has no
    // abuse shape worth a bucket.
    Route::get('/plans/{plan}/members', [PlanMemberController::class, 'index'])
        ->name('plans.members.index');

    // `verified`, per spec §7.5: changing another member's role both acts on another member's
    // behalf - it rewrites someone else's capabilities - and creates state every member sees,
    // because it writes a member.role_changed row into a feed every member reads. Both halves of
    // §7.5's rule, not one. The verdict is also recorded in VerifiedGateTest's $spec75 partition,
    // which builds its other side from the real route table, so dropping this middleware reds
    // there as well as in ChangeMemberRoleTest's unverified case.
    //
    // No per-route `auth:sanctum`: the enclosing group already applies it, and a second copy
    // would be redundant. Not throttled, for the reason plans.members.index gives above:
    // §13.4 limits plans-create, plan-invites and invite-lookup only.
    //
    // The declaration order of the middleware is immaterial: Laravel sorts route middleware
    // through the Kernel's own priority list - bootstrap/app.php never calls priority() - and
    // the measured order for this set is 0 Authenticate:sanctum, 1 SubstituteBindings,
    // 2 EnsureEmailIsVerified. Auth first, `verified` last.
    Route::patch('/plans/{plan}/members/{user}', [PlanMemberController::class, 'update'])
        ->middleware('verified')
        ->name('plans.members.update');

    // NO `verified`, and the omission is deliberate rather than an oversight - it is the one
    // exemption in spec §7.5's table that middleware cannot express. This single URI is both
    // LeavePlan and RemoveMember (§8.3): §7.5 gates removing another member and deliberately
    // does not gate leaving, because a rule that can trap someone in a Plan is the wrong rule
    // (§5.3), and route middleware runs before the controller and so before anything knows which
    // of the two a request is. RemoveMember::execute() therefore enforces verification itself, as
    // its first statement, refusing with the same 403 body EnsureEmailIsVerified returns;
    // LeavePlan does not. The precedent for recording an exemption here, with its reason, is the
    // DELETE /me comment in routes/api/v1/me.php.
    //
    // VerifiedGateTest's $spec75 map already carries this verdict as
    // 'plans.members.destroy' => false, with a note disclosing it as a FALSE NEGATIVE of that
    // map rather than a verdict: because the gate reads `verified` off this route table, it is
    // green whether or not the in-application check exists. That makes
    // LeaveAndRemoveMemberTest's unverified-remover case the only thing in the suite holding the
    // check open, which is why it asserts both the refusal and its position.
    //
    // No `throttle:`: §13.4 limits plans-create, plan-invites and invite-lookup only.
    Route::delete('/plans/{plan}/members/{user}', [PlanMemberController::class, 'destroy'])
        ->name('plans.members.destroy');
});
