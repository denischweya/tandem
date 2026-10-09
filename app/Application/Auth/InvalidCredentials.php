<?php

declare(strict_types=1);

namespace App\Application\Auth;

use RuntimeException;

/**
 * One exception for every sign-in failure - unknown email, wrong password, suspended,
 * soft-deleted. The distinction exists in the code that throws it but must never reach the
 * response, or the endpoint becomes an account-existence oracle.
 */
final class InvalidCredentials extends RuntimeException {}
