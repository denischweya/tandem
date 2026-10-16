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

// Spec §11.2: RecordActivity is the only class that inserts into plan_activity, and the hazard
// is not a bad insert - it is a tenth use case writing one inline and getting created_at wrong,
// because PlanActivity has $timestamps = false and the column is NOT NULL with no default.
//
// What this rule actually enforces, honestly (a vacuous-guard review on 2026-10-16, B5d, found
// the line that used to sit here - "a class that cannot name the model cannot write to it, so
// this rule is the mechanism" - false): toOnlyBeUsedIn() walks `use` statements and class
// references to App\Models\PlanActivity, so it stops anyone who NAMES the model. It does
// nothing against a class that never names it - EloquentPlanActivityRepository, allowed below,
// reads the feed through `DB::table('plan_activity')` and imports no model symbol at all, which
// makes its entry on this list permanently inert: this rule could never have flagged it even if
// it tried to abuse the model, because it is not abusing the model, it is going around it. A
// tenth use case doing the same - `DB::table('plan_activity')->insert(['created_at' => now()])`
// - would be invisible to this rule, to PHPStan and to Pint, which is exactly the hazard the
// rule exists to prevent. The plain-text scan below is the mechanism for THAT vector; this rule
// is the mechanism for the model-symbol vector, and neither substitutes for the other.
//
// Four names are allowed and each is load-bearing: RecordActivity is the writer;
// EloquentPlanActivityRepository is the only reader (the feed); DeleteAccount is §9.3 step 4's
// anonymisation UPDATE, which nulls actor_id and removes nothing (DeleteAccount.php:59, Wave 1a,
// already shipped); PlanActivityFactory is the test seed. Note what the rule does NOT cover: an
// update or a delete issued from one of those four. There is no mechanical guard for that, and
// the gate for it is ActivityFeedTest's "publishes no write route for the activity feed".
//
// Composer::userNamespaces() filters out anything under tests/, so a test file naming
// PlanActivity - DeleteAccountTest imports it at :13 and calls PlanActivity::create() at :271
// and :275 - is not a violation of this rule.
arch('only the activity writer, the activity reader, account deletion and the factory touch the plan_activity model')
    ->expect('App\Models\PlanActivity')
    ->toOnlyBeUsedIn([
        'App\Application\Plans\RecordActivity',
        'App\Application\Users\DeleteAccount',
        'App\Infrastructure\Persistence\Eloquent\EloquentPlanActivityRepository',
        'Database\Factories\PlanActivityFactory',
    ]);

// The third entry above, EloquentPlanActivityRepository, names a class a later task in this
// wave creates - it does not exist yet. Listing it is harmless: Blueprint::expectToOnlyBeUsedIn()
// applies each allowed name with Layer::excludeByNameStart(), a string-prefix filter that
// matches nothing when the class is absent. (A vacuous-guard review on 2026-10-16, B5d, found an
// earlier note here mis-attributed this to the FOURTH entry, PlanActivityFactory - wrong twice:
// PlanActivityFactory already exists, predating Wave 2 entirely
// (database/factories/PlanActivityFactory.php), and the entry still missing today is the third,
// not the fourth.)

// B5d (vacuous-guard review, 2026-10-16): the companion to the rule above, closing the vector it
// cannot see. Not an arch() rule because pest-plugin-arch's dependency walker inspects `use`
// statements and class references, not string literals inside a method body - it has no
// primitive for "does this file's source contain this substring", so a plain content scan is
// the only way to see a bare `DB::table('plan_activity')` call that imports no model.
//
// Fix round 1 (2026-10-16) rewrote both the needle and the allow-list below. The first draft
// matched only the literal quoted both sides - `'plan_activity'` / `"plan_activity"` - which
// missed an aliased table reference (`DB::table('plan_activity as a')`,
// `DB::table("plan_activity AS pa")`) and any raw-SQL spelling (`DB::statement('insert into
// plan_activity ...')`, `DB::insert('... plan_activity ...')`). An alias is an ordinary thing to
// write in query-builder code, not an exotic evasion, and a raw statement's created_at is
// exactly the now()-instead-of-Clock hazard this rule exists to prevent. The needle is now the
// bare word `plan_activity`, no quoting required, which catches all of the above along with the
// comment-only mentions it already caught.
//
// Deliberately lexical, not semantic, and that is a limitation worth stating rather than
// hiding: this scan cannot tell a real write from a comment that merely mentions the table
// name, so a file that legitimately discusses plan_activity in prose has to go on the allow-list
// too, exactly like a file that writes to it. It also cannot tell an INSERT from a SELECT, or a
// table reference from an incidental substring inside a longer identifier (measured: no such
// identifier exists under app/ today - `grep -rn plan_activity app/` shows the word standing
// alone in every match). And the scan is scoped to app/ only: a raw `plan_activity` reference in
// routes/, database/seeders or a published factory is covered by neither this scan nor the arch
// rule above - nothing in this suite guards those paths.
//
// The allow-list is six, by file path relative to app/, in two groups, measured today by
// `grep -rln plan_activity app/`. Two files write or read the table through the query builder:
// the model itself (it must name its own table) and the one reader a later task creates (listed
// before it exists, harmless for the same reason as the arch rule above - the scan below only
// matches files that exist today, so an absent allow-listed path changes nothing about what
// passes). The other four mention the table name only in prose: RecordActivity (this task's own
// docblock, four lines - fix round 1 found an earlier version of this comment framing its entry
// as pre-authorisation for a future quoted form it does not use; it needs no such excuse, since
// its prose already matches the bare-word needle today), DeleteAccount (§9.3 step 4's
// anonymisation comment), PurgeDeletedUsersCommand and ActivityType (one comment line each).
// That grep finds nothing else under app/ today, so the six are a closed set, not a ceiling with
// room presumed under it. The positive assertion after the scan - that it actually found the
// one match guaranteed to exist - exists so this test cannot pass by matching nothing: a
// typo'd needle would make $offenders always [] and this guard would never have been exercised.
it('allows the literal table name "plan_activity" only where it already belongs', function (): void {
    // No uses() binding exists for tests/Architecture (tests/Pest.php:26 binds only Feature),
    // so this suite never boots the application - app_path() and the File facade both resolve
    // against a bare Illuminate\Container\Container with no "path" or "files" binding and fail.
    // Plain SPL recursion against a path computed from __DIR__ needs none of that.
    $appDirectory = dirname(__DIR__, 2).'/app';

    $allowed = [
        // Write or read the table through the query builder.
        'Models/PlanActivity.php',
        'Infrastructure/Persistence/Eloquent/EloquentPlanActivityRepository.php',
        // Mention the table name only in prose.
        'Application/Plans/RecordActivity.php',
        'Application/Users/DeleteAccount.php',
        'Console/Commands/PurgeDeletedUsersCommand.php',
        'Domain/Plans/ActivityType.php',
    ];

    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDirectory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents !== false && str_contains($contents, 'plan_activity')) {
            $offenders[] = str_replace('\\', '/', substr($file->getPathname(), strlen($appDirectory) + 1));
        }
    }

    sort($offenders);

    expect($offenders)->toContain('Models/PlanActivity.php')
        ->and(array_diff($offenders, $allowed))->toBe([]);
});

// Task 8's authorization-seam gate (fix round 1, 2026-10-16). findVisibleTo() is the one method
// that enforces §5.1's 404-over-403 rule, and the same interface publishes two methods that
// deliberately go around it: findById(), which returns a Plan with no membership check at all,
// and countActiveMembers(), which is additionally a PARTIAL existence oracle: a NONZERO count
// confirms to a caller who cannot see the Plan that it exists.
//
// Only nonzero. An earlier version of this comment justified the oracle with "a real Plan always
// has at least one active member", and that premise is false - verified in the code, not
// reasoned about. DeleteAccount::resolvePlanOwnership() ARCHIVES a Plan with no eligible
// successor rather than deleting it, and DeleteAccount then marks every membership of the
// departing user `left` with no plan filter at all, so a sole owner closing their account leaves
// a real, persisted Plan with zero active members until PurgeDeletedUsersCommand removes it on
// day 30. A zero is therefore ambiguous between a malformed id, a Plan that does not exist, and
// exactly that Plan. The leak is one-directional and that is the honest version of it; the gate
// is unchanged, because only its justification was wrong.
//
// Both methods are correct, and the interface's own docblock names the callers that need them:
// §10.3's unauthenticated invite preview and §10.7 step 3's archived-Plan refusal, where the
// invite token is the credential and the caller is by definition not a member yet. So this is an
// ALLOW-LIST, not a ban. Those callers will arrive; what this gate buys is that the task adding
// one has to put itself on this test's own $allowed list, which makes bypassing the seam a
// decision someone wrote down rather than an accident nothing reds.
//
// Not an arch() rule, for a mechanical reason: pest-plugin-arch's dependency walker inspects
// `use` statements and class references, and every legitimate caller of listForActor() imports
// the very same PlanRepository class that a caller of findById() would. An import-level rule
// cannot separate the two. The precedent for a plain content scan instead is
// it('allows the literal table name "plan_activity" only where it already belongs') in this same
// file, and the same limits apply here. Stated, not hidden:
//
//   - It is lexical, so a dynamic call defeats it completely: `$m = 'findById'; $repo->$m($id);`
//     contains neither needle, and so does any call through a variable method name, a
//     __call() forward, or a wrapper that re-exposes the method under a different name.
//   - It cannot tell a call from prose. PlanRepository's class docblock discusses findById() in
//     a sentence, so that file is on the list for two reasons at once; a future file that only
//     MENTIONS either name has to be listed exactly like one that calls it.
//   - It will false-positive on an unrelated class that happens to declare its own findById() -
//     a thoroughly generic name - or countActiveMembers(). That is not a defect to engineer
//     around: adding the path to the allow-list is the intended response, and it is the same
//     deliberate decision this gate exists to force.
//   - It is scoped to app/ only, exactly like that plan_activity scan. tests/ is intentionally
//     outside it, because PlanRepositoryTest calls both methods directly and must keep doing so;
//     so are routes/, database/ and any other path, which nothing in this suite guards.
//   - THE LARGEST FALSE NEGATIVE, and the one to know about before trusting this gate: it sees
//     two method names, so a use case that goes to `Plan::query()` (or PlanMember::query())
//     directly skips findVisibleTo() without containing either needle. That route is wide open
//     BY DESIGN - App\Application depending on App\Models is deliberately allowed, and
//     arch('models do not reach into the application layer') forbids only the reverse edge, so
//     nothing in this suite stands in its way. It is not hypothetical either:
//     DeleteAccount::resolvePlanOwnership() already reads Plans exactly that way, for a
//     legitimate reason. Probed and confirmed rather than assumed. There is no mechanical guard
//     for that vector at all, here or in PHPStan; review is the only thing covering it.
//
// The positive assertion before the diff exists so this cannot pass by matching nothing: a
// typo'd needle would leave $offenders empty and the guard would never have fired.
it('allows findById() and countActiveMembers() only where the authorization seam already admits them', function (): void {
    // Path computed from __DIR__ for the same reason the plan_activity scan does it: no uses()
    // binding exists for tests/Architecture, so this suite never boots the application and
    // app_path() has no container binding to resolve against.
    $appDirectory = dirname(__DIR__, 2).'/app';

    $allowed = [
        // The declaration - which also discusses findById() in its class docblock.
        'Application/Plans/PlanRepository.php',
        // The implementation.
        'Infrastructure/Persistence/Eloquent/EloquentPlanRepository.php',
    ];

    $offenders = [];

    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($appDirectory, FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
        if ($file->getExtension() !== 'php') {
            continue;
        }

        $contents = file_get_contents($file->getPathname());

        if ($contents === false) {
            continue;
        }

        if (str_contains($contents, 'findById') || str_contains($contents, 'countActiveMembers')) {
            $offenders[] = str_replace('\\', '/', substr($file->getPathname(), strlen($appDirectory) + 1));
        }
    }

    sort($offenders);

    expect($offenders)->toContain('Application/Plans/PlanRepository.php')
        ->and(array_diff($offenders, $allowed))->toBe([]);
});
