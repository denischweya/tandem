<?php

declare(strict_types=1);

// Allow-list, not a deny-list: toOnlyUse closes every leak the old
// not->toUse(['Illuminate', 'Laravel', 'Symfony', 'Google', 'Microsoft']) rule missed, with
// no list to maintain as new vendor packages arrive. Verified directly against this
// project's installed pest-plugin-arch: a probe class using Carbon\CarbonImmutable fails
// with "it also uses 'Carbon\CarbonImmutable'"; a probe importing App\Models\User the same
// way; and - because Pest's dependency walker counts a bare call to a *user-defined* PHP
// function (ReflectionFunction::isUserDefined()) as a "use", while leaving PHP's own
// internal functions such as trim(), preg_replace() and mb_strlen() alone - config(), now(),
// app('log'), auth() and collect() are each independently caught too ("it also uses
// 'config'", etc.), with no second rule required for them.
arch('the domain layer uses nothing but itself and a small, explicit set of PHP primitives')
    ->expect('App\Domain')
    ->toOnlyUse(['App\Domain', 'DateTimeImmutable', 'DateTimeZone', 'InvalidArgumentException']);

// Redundant with the rule above in this Pest version (verified: it already catches all
// five), kept anyway as an explicit, named assertion of intent that does not depend on an
// implementation detail of how toOnlyUse classifies function calls continuing to hold
// across a future Pest upgrade.
arch('the domain layer does not reach for a global helper function')
    ->expect('App\Domain')
    ->not->toUse(['config', 'now', 'app', 'collect', 'auth']);

// Known residual gap, not closed by either rule above (verified): a root facade alias used
// with a leading backslash (e.g. \DB::table(...), \Redis::connection()) and an Illuminate
// type that appears only in a docblock, never in a `use` statement or a real type position,
// both leave this file green. Closing that requires a custom PHPStan rule over app/Domain -
// see ADR 0001 and the plan, both corrected to describe the enforcement that actually
// exists (this Pest suite) rather than a PHPStan rule that does not.

// Renamed from "controllers hold no business logic and no Eloquent" - that name claimed to
// check for business logic, which nothing here does; this checks that a controller touches
// neither Eloquent nor this project's own persistence layer. Verified: a probe controller
// importing App\Models\User and calling User::query()->...->forceFill(...)->save() fails
// with "not to use 'App\Models'"; the real HealthController (which legitimately imports
// Illuminate\Support\Facades\DB and ...\Redis for its readiness probes, neither of which is
// Eloquent) stays green.
arch('controllers touch no Eloquent and no persistence')
    ->expect('App\Http\Controllers')
    ->not->toUse(['Illuminate\Database\Eloquent', 'App\Models', 'App\Infrastructure']);

// ->classes() already excludes interfaces and enums (verified: a non-final
// ZzProbeRepository interface does not fail this rule), so Clock needed no ->ignoring() -
// that call was dead code removed here. An abstract class already fails toBeFinal() on its
// own (also verified), so a separate ->not->toBeAbstract() rule would be redundant.
arch('value objects and domain services are final')
    ->expect('App\Domain')
    ->classes()
    ->toBeFinal();

arch('strict types everywhere')
    ->expect(['App', 'Database', 'Tests'])
    ->toUseStrictTypes();
