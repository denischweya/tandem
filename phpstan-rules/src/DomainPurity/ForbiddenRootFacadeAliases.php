<?php

declare(strict_types=1);

namespace PhpStanRules\DomainPurity;

use Illuminate\Support\Facades\Facade;
use PhpParser\Node\Name;

/**
 * Shared alias list and name-matching logic for the two rules in this directory.
 *
 * tests/Architecture/DomainPurityTest.php's `toOnlyUse()` rule is a *name* check, not a
 * reflection check: it never executes the code, so it never learns that
 * `\DB::table(...)` and `Illuminate\Support\Facades\DB::table(...)` are the same call.
 * The FQCN form is caught because the string "Illuminate" appears in it. The root-alias
 * form (a leading backslash, no "Illuminate" in sight) is not - measured directly: of the
 * eight aliases below, only `\Cache::` and `\Auth::` happened to be caught (ADR 0001 and
 * the architecture test's own comment previously described the gap as just "a root facade
 * alias", without saying six of eight actually get through).
 *
 * This rule closes the gap with a reflection-free check of its own, deliberately: matching
 * on the literal written name, the same thing the arch test's gap is about, rather than
 * asking PHPStan what `\DB` resolves to. That distinction matters for `\Redis` specifically
 * - verified against this project's installed laravel/framework (v13.35.0):
 * `Illuminate\Support\Facades\Facade::defaultAliases()` does not have a 'Redis' key (Redis
 * aliasing used to be wired through `config('app.aliases')`, which this project's
 * `config/app.php` does not define), and when PHPStan reflects the bare global name
 * `Redis`, it resolves to the bundled ext-redis stub class, not
 * `Illuminate\Support\Facades\Redis`. A rule that trusted reflection would clear `\Redis::`
 * by accident, reproducing the exact gap it exists to close. `Redis` is included below
 * explicitly, for that reason, even though the Laravel-supplied list omits it.
 */
final class ForbiddenRootFacadeAliases
{
    /** @var list<string>|null */
    private static ?array $names = null;

    /**
     * Returns the alias's short name (e.g. "DB") if `$name` is exactly a root-namespaced
     * (leading-backslash) reference to one of the forbidden facade aliases, null otherwise.
     */
    public static function match(Name $name): ?string
    {
        if (! $name instanceof Name\FullyQualified) {
            return null;
        }

        $parts = $name->getParts();

        if (count($parts) !== 1) {
            return null;
        }

        return in_array($parts[0], self::names(), true) ? $parts[0] : null;
    }

    /** @return list<string> */
    private static function names(): array
    {
        if (self::$names === null) {
            $names = Facade::defaultAliases()->keys()->all();

            // Not present in Facade::defaultAliases() in this Laravel version (verified
            // above) - added explicitly rather than trusted from the upstream list, which
            // already missed it once.
            $names[] = 'Redis';

            self::$names = $names;
        }

        return self::$names;
    }
}
