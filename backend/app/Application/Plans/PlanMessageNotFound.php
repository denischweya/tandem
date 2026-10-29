<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * 404 with {"message":"That message is no longer available."}, rendered in bootstrap/app.php.
 *
 * One exception for a malformed {message}, an unknown message id, and a message id that
 * belongs to a different Plan than the one named in the route - the same shape
 * PlanInviteNotFound's own docblock gives for {invite}, and for the same reason: a caller who
 * can already see this Plan (EditMessage and DeleteMessage both call
 * PlanRepository::findVisibleTo() first) must not be able to tell "that message exists, but
 * not here" from "that message does not exist".
 */
final class PlanMessageNotFound extends RuntimeException {}
