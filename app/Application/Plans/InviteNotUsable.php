<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * One exception for an unknown token, a malformed one, an expired one, a revoked one, one that
 * has already been accepted, one issued for a DIFFERENT Plan, and one whose Plan is no longer
 * active - the same reasoning as InvalidRefreshToken, which is one exception for unknown,
 * expired, revoked and replayed. That class's own comment is the argument: distinguishing the
 * causes "would tell an attacker holding a captured token whether their replay was detected, and
 * tells a legitimate client nothing it can act on". Every case here means "ask whoever invited
 * you for a new link", which is what the message says.
 *
 * 404, not 422, which is the opposite of InvalidPasswordResetToken. The reset endpoint answers
 * 422 with errors.token because the user typed a password alongside the token and the form must
 * stay on screen. The accept-invite page has NO FIELD AT ALL - the token comes from the URL - so
 * there is nothing to attach a field error to, and the uniform 404 is worth more here than
 * form-field affinity.
 *
 * On POST /plans/{plan}/join the token does arrive in a field, and there a size:64 validation
 * rule can answer a wrong-LENGTH token with a 422 before this exception is reachable. That is
 * the same distinction: a wrong-shaped field is a field error, an unusable credential is not.
 */
final class InviteNotUsable extends RuntimeException {}
