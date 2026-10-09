<?php

declare(strict_types=1);

namespace PhpStanRules\DomainPurity;

use PHPStan\Analyser\Scope;

use function str_contains;
use function str_replace;

/**
 * Scopes the two rules in this directory to app/Domain by file path rather than by
 * declared namespace. phpstan.neon's own `paths` already limits analysis to app,
 * database, routes and tests, so this only needs to pick app/Domain out of that set.
 */
final class DomainPath
{
    public static function isInDomain(Scope $scope): bool
    {
        return str_contains(str_replace('\\', '/', $scope->getFile()), '/app/Domain/');
    }
}
