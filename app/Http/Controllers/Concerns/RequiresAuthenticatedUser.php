<?php

declare(strict_types=1);

namespace App\Http\Controllers\Concerns;

use Illuminate\Contracts\Auth\Authenticatable;
use RuntimeException;

/**
 * One copy of the null check every auth:sanctum controller action needs.
 *
 * Request::user() is nullable, and the use cases behind these routes take a non-nullable
 * user, so every action had to answer the null somehow. Four identical twelve-line blocks
 * (MeController x3, LogoutController) is what that produced.
 *
 * It throws, and that is the whole behaviour: a null here can only mean auth:sanctum was
 * removed from the route, which must fail loudly rather than be asserted away by an inline
 * type override that stops being true the moment someone makes that edit.
 *
 * NO `use App\Models\User` ANYWHERE IN THIS FILE, and the absence is load-bearing. Spec §6
 * forbids a controller touching Eloquent or App\Models, and
 * tests/Architecture/DomainPurityTest.php's "controllers touch no Eloquent and no
 * persistence" rule fails on the bare import alone - measured, by probe, in Task 7's review.
 *
 * The @template is what lets the type survive that constraint. larastan already resolves
 * Request::user() to App\Models\User|null from config('auth.providers.users.model') without
 * the class being named here, so TUser binds to App\Models\User at each call site and the
 * return feeds straight into DeleteAccount::execute(User) and Logout::execute(User) with no
 * widening. A plain `Authenticatable` return type would not: every call site would then need
 * a cast or an override to get back the type it had before calling this.
 */
trait RequiresAuthenticatedUser
{
    /**
     * @template TUser of Authenticatable
     *
     * @param  TUser|null  $user  the result of $request->user()
     * @param  string  $route  how the route reads in routes/api/v1/, for the failure message
     * @return TUser
     */
    private function requireAuthenticatedUser(?Authenticatable $user, string $route): Authenticatable
    {
        if ($user === null) {
            throw new RuntimeException(
                "{$route} was reached without an authenticated user, which means "
                .'auth:sanctum is no longer on this route.'
            );
        }

        return $user;
    }
}
