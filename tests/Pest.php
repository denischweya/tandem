<?php

declare(strict_types=1);
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Tests\TestCase;

use function Pest\Laravel\postJson;

uses(TestCase::class)->in('Feature');

/**
 * A user who can sign in: a password-bearing account that also has a profile.
 *
 * The profile is not optional decoration. UserResource dereferences
 * $this->profile->display_name with no null check, because user_profiles.user_id is
 * UNIQUE NOT NULL and registration is the only path that creates a user - the relation is
 * a database-level invariant, not a maybe. Any test that renders a session without
 * ->hasProfile() therefore fails on a null relation instead of on the behaviour it meant
 * to check.
 *
 * This lived at file scope in LoginTest.php until signIn() below needed the same thing.
 * A file-scope function in a Pest test file is process-global and cannot be redeclared, so
 * the second file that wants it must either rely on another test file's load order or
 * restate the logic. tests/Pest.php is the one place Pest loads exactly once, for every
 * suite, which makes it the durable home for both.
 *
 * @param  array<string, mixed>  $attributes
 */
function userWithPassword(string $password = 'correct horse battery staple', array $attributes = []): User
{
    return User::factory()
        ->hasProfile()
        ->create(array_merge(['password_hash' => Hash::make($password)], $attributes));
}

/**
 * Signs in through the real endpoint and returns [user, refresh token, access token].
 *
 * Through POST /auth/login rather than by inserting a refresh_tokens row directly: the
 * plaintext refresh token exists only in that response (the column stores a sha256 hash),
 * so a hand-built row would mean asserting rotation behaviour against a token this
 * application never issued.
 *
 * $installId is null by default, because most callers do not care which client shape they
 * get: a login that sends no install_id creates no devices row at all, which is the web
 * BFF's shape (ADR 0004). A caller that passes one gets the mobile shape instead, device
 * row included - which is what a test asserting that account deletion removes devices needs
 * in order to be asserting anything.
 *
 * A parameter here rather than a second helper at a test file's scope, for the reason
 * userWithPassword() above records: this file is the one place Pest loads exactly once, and a
 * file-scope copy would be a second process-global function restating this one almost
 * character for character.
 *
 * @return array{User, string, string}
 */
function signIn(
    ?User $user = null,
    string $password = 'correct horse battery staple',
    ?string $installId = null,
): array {
    $user ??= userWithPassword($password);

    $payload = [
        'email' => $user->email,
        'password' => $password,
    ];

    if ($installId !== null) {
        // platform travels with install_id: LoginController defaults a missing platform to
        // 'web', so an install_id arriving on its own would store a device whose platform
        // contradicts the client that sent it.
        $payload['install_id'] = $installId;
        $payload['platform'] = 'ios';
    }

    $data = postJson('/api/v1/auth/login', $payload)->assertOk()->json('data');

    $refreshToken = is_array($data) ? $data['refresh_token'] ?? null : null;
    $accessToken = is_array($data) ? $data['access_token'] ?? null : null;

    // A real check rather than a cast or a @var: TestResponse::json() is declared mixed, and
    // every caller feeds these two values straight into hash() and withToken(). If the login
    // response ever stops carrying both as strings, that should fail here, by name, instead
    // of surfacing as a TypeError inside an unrelated assertion in some other file.
    if (! is_string($refreshToken) || ! is_string($accessToken)) {
        throw new RuntimeException('POST /api/v1/auth/login did not return both tokens as strings.');
    }

    return [$user, $refreshToken, $accessToken];
}

/**
 * Captures everything written to the configured log channel for the rest of the current test.
 *
 * A Monolog TestHandler rather than Log::spy(): the spy replaces the LogManager, so it proves
 * only that a facade method was called, and Log::shouldHaveReceived() is absent from the Log
 * facade's documented method list, so level 8 rejects it outright (measured). Pushing a
 * handler asserts the record actually
 * reached the logging pipeline - level, message and context - which is what the structured
 * `event` keys in RefreshSession, RefreshTokenRotator, OutboxDispatcher,
 * PurgeDeletedUsersCommand and routes/console.php exist to be found by.
 *
 * pushHandler() puts the TestHandler at the FRONT of the stack, which matters under
 * phpunit.xml's LOG_CHANNEL=null: NullHandler swallows a record and stops propagation, so a
 * handler appended after it would never see anything. TestHandler bubbles, so the NullHandler
 * behind it still runs and nothing is written to disk.
 *
 * Nothing pops the handler: the application - and with it the LogManager and its channels - is
 * rebuilt for every test, so the handler cannot leak into the next one.
 */
function captureLogs(): TestHandler
{
    $logger = Log::getLogger();

    if (! $logger instanceof Logger) {
        throw new RuntimeException(
            'captureLogs() needs the configured log channel to be backed by Monolog; got '.$logger::class.'.'
        );
    }

    $handler = new TestHandler;
    $logger->pushHandler($handler);

    return $handler;
}

/**
 * The records captureLogs() saw, as [level, message, context] triples.
 *
 * TestHandler::getRecords() returns Monolog\LogRecord objects; flattening to a list of arrays keeps the assertions in the tests
 * readable without any of them having to know Monolog's record shape.
 *
 * @return list<array{level: string, message: string, context: array<mixed>}>
 */
function capturedLogRecords(TestHandler $handler): array
{
    return array_values(array_map(
        fn (LogRecord $record): array => [
            'level' => $record->level->getName(),
            'message' => $record->message,
            'context' => $record->context,
        ],
        $handler->getRecords(),
    ));
}
