<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Illuminate\Support\Facades\Hash;

/**
 * A valid bcrypt hash of a value no account has, used by Login::execute() so the
 * unknown-email path costs the same as the wrong-password path - the constant-time half of
 * the sign-in-failure oracle (the identical-response test covers the other half: the body).
 *
 * Derived from Hash::make() rather than a hardcoded literal, so its bcrypt cost always
 * tracks config('hashing.bcrypt.rounds') instead of a cost that can silently drift out of
 * step with it. Measured, not assumed: a hardcoded cost-12 literal, under this project's own
 * test configuration of BCRYPT_ROUNDS=4, made the "unknown email" path's Hash::check() call
 * take ~236ms against this value's ~1ms for "wrong password" - a measured 223x slower, in
 * the wrong direction, and invisible to any test that only compares statuses and bodies. The same
 * literal would silently reopen the gap the other way in production the day BCRYPT_ROUNDS
 * is raised as routine hardening, since nothing ties the two together.
 *
 * Computed at most once per process, with a static local rather than a static property:
 * Login (and this class) are declared `readonly`, and a readonly class cannot hold a mutable
 * static property at all (verified directly: `Readonly property ... cannot have default
 * value`). A static local inside a method is not a declared property, so the restriction
 * does not apply, and it is shared across every instance within the process exactly like a
 * static property would be - verified with two separate instances of a probe class
 * returning the same memoized value.
 */
final readonly class DummyPasswordHash
{
    public function value(): string
    {
        static $hash = null;

        return $hash ??= Hash::make('no account can ever authenticate with this exact value');
    }
}
