<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

// Not a unit test of a class: this asserts a property of the *build*, which is where the
// byte-equality gate actually lives. Spec §5 requires the committed document to be independent
// of the machine that generated it.
//
// WHAT THIS PROVES, measured rather than asserted by its own comment. The 2026-10-08 final
// review read this test as inert by construction - the generator applies all six variables as
// command-scoped assignments, which beat both the inherited environment and .env, so the
// subprocess's config would be identical to a plain `make openapi` run and the byte comparison
// would be implied by `openapi-check`. The premise is right and the conclusion is wrong, and
// the difference is the whole point of the test: the assignments beat the inherited environment
// ONLY WHILE THEY ARE THERE. This test is a guard on the PINS, not on the environment.
//
// Demonstrated end to end, in three steps, each one run:
//
//   1. Make APP_NAME reach the document, by replacing config/scramble.php's `ui.title`
//      literal with config('app.name') - the one config edit spec §5.1 names as the thing
//      separating these pins from dead weight.
//   2. Generate with APP_NAME=Clobbered in the ambient environment, pin in place:
//      "title": "Tandem". The pin wins, as the review said it would.
//   3. Remove `APP_NAME=Tandem \` from bin/generate-openapi.sh and generate again, same
//      ambient value: "title": "Clobbered" - so the hostile environment DOES reach the
//      subprocess - and this test FAILS, on exactly that line of the diff.
//
// So the honest statement is not "this cannot fail" but "this cannot fail TODAY, and the
// reason is recorded in spec §5.1": no variable the generator pins currently reaches the
// document at all. Neither predicted leak is live - `ui.title` is a literal, so
// config('app.name') is never read; APP_LOCALE reaches the document only through __(), which
// nothing calls and which has no lang/ directory to resolve against; and AUTH_MODEL was
// re-measured at GET /me, where UserResource::make() wraps $request->user() before Scramble
// can infer anything from the call-site type. The test's failure condition is the CONJUNCTION
// of "a pin is missing" and "that variable reaches the document", and the second half is
// false for every one of the six. That is a latent guard, not a dead one, and it is the
// cheapest available insurance against the Wave 0 failure - a fresh clone with no .env
// generating "title": "Laravel" and failing the byte gate for no reason.
//
// The rule the list below used to state - "every variable the generator pins must appear here,
// or the pin it advertises is untested" - is not the inverse of the truth, but it does
// over-claim in one direction. Absence is as it says: a pinned variable missing from this list
// has its pin exercised by nothing. Presence is weaker than it reads: listing a variable makes
// its pin testable, not tested, because nothing yet carries it into the output.
//
// What this adds over `openapi-check`, concretely: openapi-check runs the generator with
// whatever environment the developer happens to have, so it answers "is the committed file
// what this machine produces". This answers "is the committed file what ANY machine produces",
// which is guarantee 1's actual claim, and it is the only thing in the repo that asks.
it('generates a byte-identical document under a hostile environment', function (): void {
    $hostile = [
        'APP_NAME' => 'Clobbered',
        'APP_LOCALE' => 'fr',
        'APP_FALLBACK_LOCALE' => 'de',
        'APP_URL' => 'https://someone-elses-host.example',
        'APP_ENV' => 'production',
        // One entry per variable bin/generate-openapi.sh pins, so that a pin whose variable
        // ever starts reaching the document is exercised the moment it does. AUTH_MODEL was
        // missing: it is one of the two leaks the generator's own comment names as going live
        // the moment a route exists under api/v1 - Scramble's RequestExtension reads
        // config('auth.providers.users.model') when inferring the type of $request->user().
        //
        // An EXISTING class, deliberately, and not a made-up name: RequestExtension
        // ::getUserModelClass() guards with class_exists($model) and falls back to
        // App\Models\User when it fails, so a hostile value naming a class that does not
        // exist is neutralised by Scramble itself and could never change the document - a
        // hostile value that cannot be hostile. UserProfile is this project's own model, so
        // it cannot quietly disappear, and it is unambiguously not the user model.
        'AUTH_MODEL' => 'App\Models\UserProfile',
    ];

    $out = tempnam(sys_get_temp_dir(), 'openapi-hostile-').'.json';

    $result = Process::env($hostile)
        ->path(base_path())
        ->run(['./bin/generate-openapi.sh', $out]);

    expect($result->successful())->toBeTrue($result->errorOutput());

    $committed = file_get_contents(base_path('openapi.json'));
    $generated = file_get_contents($out);

    @unlink($out);

    expect($generated)->toBe($committed);
})->skip(
    PHP_OS_FAMILY === 'Windows',
    'The generator is a bash script.',
);
