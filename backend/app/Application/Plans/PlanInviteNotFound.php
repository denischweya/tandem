<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * 404 with {"message":"That invite is no longer available."}, rendered in bootstrap/app.php.
 *
 * One exception for a malformed {invite}, an unknown id, and an invite that belongs to a
 * different Plan - §17.3's third IDOR parameter. The precise wording is safe for §5.2's
 * reason: findVisibleTo() runs first, so this is only reachable by a caller who has already
 * proved they can see the Plan and hold `manage members` on it.
 *
 * NOT the same class as InviteNotUsable, which §10.3 and §10.7 raise for a TOKEN that is
 * unknown, expired, revoked, already accepted or whose Plan has gone - that one has its own
 * message and is reached by an unauthenticated or non-member caller, where nothing may be
 * distinguishable. This one is the authenticated list-and-revoke path.
 */
final class PlanInviteNotFound extends RuntimeException {}
