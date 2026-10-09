<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Process;

// Not a unit test of a class: this asserts a property of the *build*, which is where the
// byte-equality gate actually lives. Spec §5 requires the committed document to be
// independent of the machine that generated it, and names two leaks that go live the moment
// a route exists under api/v1 - which this task is the first to do.
it('generates a byte-identical document under a hostile environment', function (): void {
    $hostile = [
        'APP_NAME' => 'Clobbered',
        'APP_LOCALE' => 'fr',
        'APP_FALLBACK_LOCALE' => 'de',
        'APP_URL' => 'https://someone-elses-host.example',
        'APP_ENV' => 'production',
        // Every variable bin/generate-openapi.sh pins must appear here, or the pin it
        // advertises is untested. AUTH_MODEL was missing: it is one of the two leaks the
        // generator's own comment names as going live the moment a route exists under
        // api/v1 - Scramble's RequestExtension reads config('auth.providers.users.model')
        // when inferring the type of $request->user().
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
