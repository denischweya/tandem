<?php

declare(strict_types=1);

namespace App\Application\Auth;

use RuntimeException;

/**
 * One exception for unknown, expired, revoked and replayed. A client cannot act differently
 * on the distinction - every case means "sign in again" - and reporting which one it was
 * tells an attacker holding a captured token whether their replay was detected.
 */
final class InvalidRefreshToken extends RuntimeException {}
