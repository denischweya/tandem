<?php

declare(strict_types=1);
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Infrastructure\Auth\EmailVerificationLink;
use App\Infrastructure\Notifications\Mail\ResetPasswordMail;
use App\Infrastructure\Outbox\OutboxDispatcher;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\PendingCommand;
use Monolog\Handler\TestHandler;
use Monolog\Logger;
use Monolog\LogRecord;
use Tests\TestCase;

use function Pest\Laravel\artisan;
use function Pest\Laravel\postJson;

uses(TestCase::class)->in('Feature');

/**
 * EVERY SHARED TEST HELPER LIVES IN THIS FILE, and the rule has no exceptions worth the
 * trouble of making. Pest loads each test file into the global namespace, so a function
 * declared at a test file's scope is process-global: the second file that wants it can only
 * get it by depending on another file's load order, and a file that wants its OWN helper of
 * the same name gets a fatal "Cannot redeclare". That is not hypothetical - `member()` is a
 * four-character, entirely generic name, declared in DeleteAccountTest.php, that the first
 * Wave 2 Plan test to want a member row would have collided with. tests/Pest.php is the one
 * place Pest loads exactly once, for every suite, so it is the only safe home.
 *
 * Collected here from six test files, each of which had grown its own: validRegistration
 * (RegisterTest), signedVerificationQuery (VerifyEmailTest), requestReset
 * (PasswordResetTest), authenticatedUser (UpdateProfileTest), member (DeleteAccountTest),
 * softDeletedDaysAgo and runArtisan (PurgeDeletedUsersTest).
 */

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
 * A parameter here rather than a second helper, for the reason the file header records: a
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

/**
 * Drops the auth manager's resolved guards, so the next request re-authenticates from scratch.
 *
 * Needed by any test that makes an AUTHENTICATED request and then asserts that a token no
 * longer works. Illuminate\Auth\GuardHelpers caches the resolved user on the guard instance,
 * and Sanctum's guard is registered with app()->refresh('request', $guard, 'setRequest') -
 * which swaps the request on each of a test's HTTP calls but never clears that cached user.
 * The guards are singletons on the one application the test runs against, so the cache
 * survives from one call to the next.
 *
 * Measured with a probe, because the consequence is a security test that cannot fail: sign in,
 * GET /me (200), delete every personal_access_tokens row, GET /me again - and the second /me
 * still returns 200, from the cache, with zero token rows in the database. After
 * Auth::forgetGuards() the same request returns 401. Any "this credential is dead now"
 * assertion placed after an authenticated request in the same test is therefore vacuous
 * without this call.
 */
function forgetAuthenticatedUser(): void
{
    Auth::forgetGuards();
}

/**
 * A registration body the API accepts, with any field overridden.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validRegistration(array $overrides = []): array
{
    return array_merge([
        'email' => 'ada@example.test',
        'password' => 'correct horse battery staple',
        'display_name' => 'Ada Lovelace',
        'timezone' => 'Europe/London',
        'locale' => 'en',
    ], $overrides);
}

/** Returns the query string the web app relays back to this API from a verification link. */
function signedVerificationQuery(User $user): string
{
    $link = app(EmailVerificationLink::class)->for($user);

    return (string) parse_url($link, PHP_URL_QUERY);
}

/**
 * Requests a password reset and returns the plaintext token the email carries.
 *
 * The caller must have called Mail::fake() first - the token exists nowhere else, since
 * password_reset_tokens stores only a sha256 hash and the outbox payload is encrypted.
 */
function requestReset(User $user): string
{
    postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();

    app(OutboxDispatcher::class)->dispatchPending();

    $captured = null;
    Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$captured): bool {
        $captured = $mail->token;

        return true;
    });

    return (string) $captured;
}

/**
 * A signed-in user with a profile whose fields are known, for the /me endpoints.
 *
 * @return array{User, string}
 */
function authenticatedUser(): array
{
    $user = User::factory()->hasProfile(['display_name' => 'Before', 'timezone' => 'UTC', 'locale' => 'en'])
        ->create(['password_hash' => Hash::make('correct horse battery staple')]);

    [, , $access] = signIn($user);

    return [$user, $access];
}

/**
 * An active plan_members row, with an explicit joined_at so "longest-standing" is deterministic.
 *
 * Through the factory rather than PlanMember::create(): nothing is mass-assignable on that
 * model (see Plan's docblock), and Illuminate's Factory wraps instantiation in
 * Model::unguarded(), so a factory is the one array-shaped way to seed a row. A direct
 * ::create() now throws MassAssignmentException, which is the behaviour being bought.
 */
function member(Plan $plan, User $user, MemberRole $role, string $joinedAt): PlanMember
{
    return PlanMember::factory()->create([
        'plan_id' => $plan->id,
        'user_id' => $user->id,
        'role' => $role->value,
        'status' => MemberStatus::Active->value,
        'joined_at' => $joinedAt,
    ]);
}

/** A user soft-deleted $days ago, which is what the day-30 purge selects on. */
function softDeletedDaysAgo(int $days): User
{
    $user = User::factory()->hasProfile()->create();
    $user->delete();
    DB::table('users')->where('id', $user->id)->update(['deleted_at' => now()->subDays($days)]);

    return $user;
}

/**
 * Pest\Laravel\artisan() is typed `PendingCommand|int` because the real method it delegates
 * to - Illuminate\Foundation\Testing\Concerns\InteractsWithConsole::artisan() - genuinely
 * returns `int` when $mockConsoleOutput is false. This suite never disables console mocking
 * (nothing calls withoutMockingConsoleOutput()), so the PendingCommand branch is the only one
 * ever taken; this narrows the union with a real, checked instanceof rather than with an
 * inline type override, which this project's level-8 constraint forbids.
 *
 * @param  array<string, mixed>  $parameters
 */
function runArtisan(string $command, array $parameters = []): PendingCommand
{
    $pending = artisan($command, $parameters);

    if (! $pending instanceof PendingCommand) {
        throw new RuntimeException('Expected artisan() to return a PendingCommand - console output mocking must be disabled.');
    }

    return $pending;
}
