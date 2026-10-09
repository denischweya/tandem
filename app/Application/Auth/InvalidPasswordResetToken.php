<?php

declare(strict_types=1);

namespace App\Application\Auth;

use RuntimeException;

/**
 * One exception for unknown, used, expired and wrong-email tokens - the same reasoning as
 * InvalidRefreshToken, which is one exception for unknown, expired, revoked and replayed. A
 * client cannot act differently on the distinction - every case means "request a new reset
 * link" - and reporting which one it was would tell someone probing this endpoint more than
 * the generic message does.
 */
final class InvalidPasswordResetToken extends RuntimeException {}
