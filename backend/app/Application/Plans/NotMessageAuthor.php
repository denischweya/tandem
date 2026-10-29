<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * 403 with {"message":"You can only edit or delete your own messages."}, rendered in
 * bootstrap/app.php.
 *
 * Thrown by EditMessage and DeleteMessage when the message's own user_id does not match the
 * actor attempting to edit or delete it. Nothing in PlanPermissions enters this check -
 * authorship, not role, is the whole rule, so the Plan's owner is refused exactly as any other
 * member is refused when the message is not theirs.
 *
 * Deliberately left reportable, NOT added to bootstrap/app.php's dontReport() list - unlike a
 * stale-link or a probed id, a signed-in member attempting to edit or delete someone else's
 * message is a signal worth seeing, the same argument OwnerInviteNotAcceptable's own docblock
 * makes for itself.
 */
final class NotMessageAuthor extends RuntimeException {}
