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
// THE THREE INTERNAL NAMES IN THIS ALLOW-LIST ARE DECORATION AS FAR AS PEST IS CONCERNED, and
// that is measured. pest-plugin-arch's dependency walker does not count a PHP INTERNAL class as
// a use at all, so toOnlyUse() cannot restrict them: reducing this rule to
// ->toOnlyUse(['App\Domain']) leaves the whole suite green, and a probe domain class using
// DateTime, ArrayObject, SplQueue, Closure and RuntimeException is flagged for none of them.
// What this rule actually enforces, on its own, is "uses nothing USERLAND but itself" - which
// is still worth having (the userland and vendor halves both bite: see the probes named below),
// but is not what its name says.
//
// The gap is closed by a PHPStan rule, not by rewording the name: phpstan-rules/src/
// DomainPurity/NoUnlistedGlobalClassRule.php enforces exactly this allow-list over every class
// position in app/Domain, and the two share one policy by keeping the same three names. It
// matters that mutable DateTime cannot slip in, because spec §6.1 promises the availability
// engine strict purity over precisely the DST and midnight arithmetic a mutable date type gets
// wrong. The names stay here as well, so the policy is readable from the test that is named
// after it.
arch('the domain layer uses nothing userland but itself (internals: see the PHPStan rule)')
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
//
// The internals gap recorded above the first rule is closed by a third rule in the same
// directory, on the same reasoning: a guard whose name promises more than it checks is worse
// than one that checks less and says so.

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

// Added after the fact, for a direction none of the rules above happened to cover:
// User::markEmailAsVerified() imported App\Application\Auth\VerifyEmail and called
// app(VerifyEmail::class)->execute($this), a model reaching UP into the use-case layer that
// orchestrates it - and since VerifyEmail::execute() takes a User, that is also a
// class-level cycle, not just a layering inversion. Application legitimately depends on
// Models everywhere (ADR 0001) - the violation is only the reverse edge. Verified: a probe
// model under App\Models importing App\Application\Auth\VerifyEmail fails this rule with
// "not to use 'App\Application'"; reverting the fix and running this file alone reproduces
// that failure against the real User model, confirming the rule - not a coincidence of the
// probe - is what catches it.
arch('models do not reach into the application layer')
    ->expect('App\Models')
    ->not->toUse('App\Application');

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
