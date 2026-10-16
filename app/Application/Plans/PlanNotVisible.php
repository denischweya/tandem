<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * One exception for a malformed {plan}, a Plan that does not exist, an actor with no
 * plan_members row, and an actor whose row's status does not grant access - rendered 404 with
 * {"message":"Plan not found."} by one closure in bootstrap/app.php.
 *
 * This is InvalidRefreshToken's pattern, and that class's own docblock carries the argument for
 * the auth version of it, paraphrased rather than quoted here: a client cannot act differently
 * on the distinction, since every case means sign in again, and reporting which case it was
 * tells an attacker holding a captured token whether their replay was detected. The same
 * reasoning covers "does this Plan exist" and "am I in it" - spec §8.1, quoted exactly: "A 403
 * confirms the resource exists, which leaks Plan existence to non-members."
 *
 * The malformed case is in the list deliberately. Route::whereUuid('plan') would also answer
 * 404, earlier and for free, but with the ROUTER's body - so one route would answer two
 * different 404 bodies depending on whether the id looked like a uuid, which is the one thing
 * §8.1 is trying to prevent.
 */
final class PlanNotVisible extends RuntimeException {}
