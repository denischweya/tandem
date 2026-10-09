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

// Known residual gap in the rule above, measured directly rather than assumed, and wider
// than "a root facade alias" suggests: of eight root aliases tried with a leading
// backslash, six pass this file clean - \DB::table(...), \Log::info(...), \Hash::make(...),
// \Redis::connection()->ping(), \Http::get(...) and \Storage::disk(...)->get(...) all leave
// every assertion above green. Only \Cache:: and \Auth:: fail here ("it also uses 'Cache'"
// / "'Auth'"), and for a reason that has nothing to do with the alias form: `cache` and
// `auth` are also global helper functions, already named in the not->toUse() rule two
// classes up, which is a function-call check that happens to share a spelling with these
// two facades. The other six have no such coincidence and are not caught by anything here.
// An Illuminate type referenced only in a docblock, never in a `use` statement or a real
// type position, leaves this file green too, though Pint closes that one in practice by
// rewriting FQCN docblock types into imports (verified).
//
// Both the alias gap (all eight, not just six) and the docblock gap are closed by a
// PHPStan rule over app/Domain - phpstan-rules/src/DomainPurity/, registered in
// phpstan.neon - which does not trust what an alias resolves to, only what is written, so
// it needs no update when Laravel adds or removes an alias. See ADR 0001 (revised
// 2026-10-09) for the full account, including why \Redis needed an explicit addition to
// the alias list the PHPStan rule relies on. This Pest suite stays in place regardless: it
// is the one guard that also catches a bare `use Illuminate\...;` import with no call at
// all.

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
