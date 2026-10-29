<?php

declare(strict_types=1);
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Users\Email;
use App\Http\Requests\Concerns\ReadsPageQuery;
use App\Infrastructure\Auth\EmailVerificationLink;
use App\Infrastructure\Auth\PlanInviteTokens;
use App\Infrastructure\Notifications\Mail\ResetPasswordMail;
use App\Infrastructure\Outbox\OutboxDispatcher;
use App\Models\Plan;
use App\Models\PlanInvite;
use App\Models\PlanMember;
use App\Models\PlanMessage;
use App\Models\User;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Http\Request;
use Illuminate\Routing\Route as RoutingRoute;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Str;
use Illuminate\Testing\PendingCommand;
use Illuminate\Translation\PotentiallyTranslatedString;
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

/**
 * An invite issued through the production path, and its plaintext token.
 *
 * Through PlanInviteTokens::issue() rather than PlanInvite::factory(): plan_invites stores only
 * sha256(plaintext), so a factory row is not a credential anything can present, and a fixture
 * that hashed its own token would be a second definition of the token shape - which is exactly
 * the drift PlanInviteFactory's docblock refuses for expires_at.
 *
 * @return array{PlanInvite, string}
 */
function invited(Plan $plan, User $invitedBy, MemberRole $role, ?string $email = null): array
{
    $issued = app(PlanInviteTokens::class)->issue(
        $plan,
        $invitedBy,
        $email === null ? null : Email::fromString($email),
        $role,
    );

    return [$issued->invite, $issued->token];
}

/**
 * A Plan plus its owner's ACTIVE plan_members row, which is the shape every Plan endpoint
 * assumes: spec §9.1's invariant is that an active Plan has exactly one active owner row whose
 * user_id equals plans.owner_id, and PlanFactory writes plans.owner_id without the membership.
 * A Plan created by the factory alone therefore has an owner who is not a member of it, and
 * findVisibleTo() answers 404 for them - which is a confusing way for a Plan test to fail.
 *
 * In tests/Pest.php and not in a test file, for the reason this file's header gives: Pest loads
 * each test file into the global namespace, so a file-scope helper is process-global and a
 * second file wanting its own of the same name gets a fatal "Cannot redeclare".
 */
function ownedPlan(User $owner): Plan
{
    $plan = Plan::factory()->create(['owner_id' => $owner->id]);

    member($plan, $owner, MemberRole::Owner, '2026-01-01T00:00:00+00:00');

    return $plan;
}

/**
 * A brand-new user with an active `member` row on $plan, for the chat suite's send/list tests.
 *
 * Moved here from tests/Feature/Chat/SendMessageTest.php (fix round 1): this file's own header
 * states the rule - Pest loads every test file into the global namespace, so a bare function
 * declared in one test file is process-global, and a SIBLING file that calls it without its own
 * copy or this file's copy is one `require` order away from "Call to undefined function
 * activeMemberOf()". tests/Feature/Chat/ListPlanMessagesTest.php called this with no definition
 * of its own, which is exactly that defect - measured by running that one file in isolation.
 *
 * Field-by-field through `new PlanMember`, not the `member()` helper above: PlanMember is
 * $guarded = ['*'] (see Plan's own docblock for why), and this is kept as originally authored
 * rather than refactored onto member()'s MemberRole/MemberStatus-typed signature, since this
 * is a relocation, not a rewrite.
 */
function activeMemberOf(Plan $plan): User
{
    $user = User::factory()->hasProfile()->create();
    $member = new PlanMember;
    $member->id = (string) Str::uuid7();
    $member->plan_id = $plan->id;
    $member->user_id = $user->id;
    $member->role = 'member';
    $member->status = 'active';
    $member->joined_at = now();
    $member->save();

    return $user;
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

/**
 * The resources section's App\Http\Requests\Concerns\ReadsPageQuery has no consumer yet - the
 * three list FormRequests that will `use` it belong to later tasks - and with none, PHPStan's
 * trait.unused rule fires on the trait's own file. This class is what resolves that: a NAMED
 * subclass, not an anonymous one, because PHPStan narrows an anonymous class's own type only
 * from a return EXPRESSION, not through a helper function's declared return type (tried first;
 * typing that function `: Request` hid the trait's method again, and a trait cannot be named
 * in a PHPDoc intersection type either - `return.unresolvableType`).
 *
 * Illuminate\Http\Request's query-string methods (string(), integer()) need no booted
 * container, so constructing this stays inside the Unit suite's "boots no application" rule.
 *
 * Declared here, not in the one test file that introduced it (fix round 1, Important 5): a
 * class declared in any test file is process-global the moment Pest loads it - loading every
 * file into one global namespace is how tests/Pest.php's own docblock states the "shared
 * helper" rule - and this name is the one a future task adding its own ReadsPageQuery-using
 * FormRequest unit test would naturally reach for too. tests/Pest.php is Pest's one
 * exactly-once-per-run file, so it is the only place a second, differently-shaped declaration
 * of this name cannot happen by accident.
 */
final class PageQueryTestRequest extends Request
{
    use ReadsPageQuery;
}

/**
 * Illuminate\Contracts\Validation\ValidationRule::validate()'s $fail parameter is PHPDoc-typed
 * as Closure(string, ?string=): PotentiallyTranslatedString, not Closure(string): void - the
 * real validator's $fail is chainable (->translate()), so its return value is never void. A
 * void-returning closure is a `void` vs `PotentiallyTranslatedString` return-type mismatch at
 * level 8 (argument.type), measured rather than assumed from an earlier draft's closure shape.
 *
 * PotentiallyTranslatedString's own source leaves its $translator constructor parameter
 * untyped, but Larastan's stub for it narrows that to Illuminate\Contracts\Translation\
 * Translator, so `null` is rejected even though the real constructor would accept it. The
 * stub below implements the interface and is never called - ->translate() and
 * ->translateChoice() are the only methods that touch it, and neither runs in any test that
 * uses this helper - so constructing it needs no booted container, staying inside the Unit
 * suite's "boots no application" rule, same as instantiating Illuminate\Http\Request directly
 * does elsewhere in the tests/Unit/Http/ subtree.
 *
 * Declared here, not in ValidCursorTest.php (fix round 1, Important 5): same reasoning as
 * PageQueryTestRequest above - "recordingFailClosure" is generic enough that a future
 * ValidationRule unit test (this app has two more: ValidEmail, IanaTimeZone, neither tested
 * this way yet) could plausibly declare the same name, which is a fatal, not a test failure.
 *
 * @param  array<int, string>  $failures
 */
function recordingFailClosure(array &$failures): Closure
{
    $translator = new class implements Translator
    {
        /** @param array<array-key, string> $replace */
        public function get($key, array $replace = [], $locale = null)
        {
            throw new LogicException('not used: this helper never calls translate()');
        }

        /**
         * @param  Countable|int|float|array<array-key, mixed>  $number
         * @param  array<array-key, string>  $replace
         */
        public function choice($key, $number, array $replace = [], $locale = null)
        {
            throw new LogicException('not used: this helper never calls translateChoice()');
        }

        public function getLocale()
        {
            throw new LogicException('not used: this helper never calls translate()');
        }

        public function setLocale($locale)
        {
            throw new LogicException('not used: this helper never calls translate()');
        }
    };

    return function (string $message, ?string $translation = null) use (&$failures, $translator): PotentiallyTranslatedString {
        $failures[] = $translation ?? $message;

        return new PotentiallyTranslatedString($message, $translator);
    };
}

/**
 * Every route the API publishes under api/v1, as [method, path-as-written-in-the-document, route].
 *
 * Read off Laravel's route table rather than off openapi.json, so an endpoint added without
 * regenerating fails the assertions that use this instead of being skipped by them. The `api/v1`
 * prefix is stripped because `servers` already carries it, which is why the document's keys are
 * `/auth/login` and not `/api/v1/auth/login`.
 *
 * Moved here from tests/Feature/Contract/OpenApiContractTest.php, unchanged, because more than
 * one file now wants it and Pest loads each test file into the global namespace: a second copy
 * would be a fatal "Cannot redeclare documentedRoutes()", not a test failure. Its remaining
 * call sites in that file keep working, because this file is the one place Pest loads exactly
 * once for every suite.
 *
 * @return list<array{string, string, RoutingRoute}>
 */
function documentedRoutes(): array
{
    $routes = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $uri = $route->uri();

        if (! str_starts_with($uri, 'api/v1/')) {
            continue;
        }

        foreach ($route->methods() as $method) {
            if ($method === 'HEAD') {
                continue;
            }

            $routes[] = [strtolower($method), '/'.substr($uri, strlen('api/v1/')), $route];
        }
    }

    return $routes;
}

/**
 * Every Plan route, as documentedRoutes() rows.
 *
 * The filter is on element [1], which documentedRoutes() has ALREADY stripped the `api/v1/`
 * prefix from: the value is `/plans/{plan}`, never `api/v1/plans/{plan}`. A filter written
 * against 'api/v1/plans' matches zero rows, every foreach over this helper never executes, and
 * a refusal suite built on it passes having asserted nothing. That is why every such suite
 * asserts a counter against a literal, and why one of them compares these keys against a
 * hand-written list: a derived list and a literal cannot be emptied together.
 *
 * `=== '/plans'` is spelled out beside the prefix test rather than folded into
 * str_starts_with($row[1], '/plans'), so that a future `/plansomething` route does not quietly
 * join the matrix.
 *
 * Guarantee: every row this returns is a real, currently-registered route - it cannot go stale
 * by omission, because it is filtered from documentedRoutes() rather than listed by hand. Limit:
 * it only sees routes whose path IS `/plans` or starts with `/plans/`; a Plan-related route
 * registered under a different prefix would silently escape every consumer of this helper, which
 * is exactly why the accounting suite below checks documentedRoutes() against the union of this,
 * Wave 1a's routes and a named exemption list, rather than trusting this helper alone.
 *
 * @return list<array{string, string, RoutingRoute}>
 */
function planRouteTable(): array
{
    return array_values(array_filter(
        documentedRoutes(),
        fn (array $row): bool => $row[1] === '/plans' || str_starts_with($row[1], '/plans/'),
    ));
}

/**
 * Every Plan route as "<method> <path>", sorted - the key every refusal matrix is written against.
 *
 * Guarantee: sorted ascending, derived from planRouteTable(), so every caller comparing this
 * against its own literal is comparing against the same order every time. Limit: sorting
 * destroys registration order, which is why a test that needs to reason about WHICH row fails
 * first (registration order matters for a shared-fixture control) reads planRouteTable()
 * directly instead of this.
 *
 * @return list<string>
 */
function planRouteKeys(): array
{
    $keys = array_map(
        fn (array $row): string => $row[0].' '.$row[1],
        planRouteTable(),
    );

    sort($keys);

    return $keys;
}

/**
 * A concrete request URI for a documented path, with every path parameter substituted.
 *
 * The `/api/v1` prefix is added back here, because documentedRoutes() strips it.
 *
 * The throw is a growth guard. A route that gains a third path parameter would otherwise be
 * requested with a literal `{thing}` segment, match no route, and answer the ROUTER's
 * pretty-printed 404 - which a body assertion would then report as a wrong refusal body rather
 * than as a missing parameter, sending the implementer after the wrong defect.
 *
 * Guarantee: throws rather than silently requesting a malformed URI. Limit: it does not know
 * which parameters a given path actually needs - passing an unused name is silently harmless,
 * and that is deliberate, because it lets a caller hand the same parameter map to every route
 * regardless of how many of those parameters each one actually takes.
 *
 * @param  array<string, string>  $parameters
 */
function planRouteUri(string $path, array $parameters): string
{
    $uri = $path;

    foreach ($parameters as $name => $value) {
        $uri = str_replace('{'.$name.'}', $value, $uri);
    }

    if (str_contains($uri, '{')) {
        throw new RuntimeException("Unsubstituted path parameter in [{$uri}]: add it to the parameter map in the calling suite.");
    }

    return '/api/v1'.$uri;
}

/**
 * A request body each Plan route's FormRequest accepts, keyed "<method> <path>".
 *
 * Every refusal suite needs these because validation runs BEFORE the repository: a FormRequest
 * is resolved by the controller dispatcher's parameter resolution, and Laravel's
 * registerRequestValidation() afterResolving hook calls validateResolved() there - so a
 * bodyless PATCH /plans/{plan} answers 422 and never reaches findVisibleTo(). A suite that
 * accepted "404 or 422" would pass for a route that never consulted the Plan at all.
 *
 * Keyed, rather than derived, because only the route's own FormRequest knows what it accepts.
 * The keys are checked against planRouteKeys() by callers such as
 * tests/Feature/Security/NonMemberRefusalTest.php, so a route added without a payload row
 * fails the calling suite rather than being skipped by it.
 *
 * Guarantee: every entry here is valid against its route's real FormRequest at the time this
 * comment was written (verified by reading each rules() method). Limit: this map is
 * hand-written, so it cannot see a rule change in the FormRequest it describes - nothing forces
 * it to stay accurate except the suites that exercise it; see planRouteKeys() for the one
 * dimension (key coverage) that IS enforced mechanically.
 *
 * @return array<string, array<string, mixed>>
 */
function planRoutePayloads(): array
{
    return [
        'get /plans' => [],
        'post /plans' => ['title' => 'Trip to Lisbon', 'timezone' => 'Europe/London'],
        'get /plans/{plan}' => [],
        'patch /plans/{plan}' => ['title' => 'Renamed by the refusal matrix'],
        'delete /plans/{plan}' => [],
        'post /plans/{plan}/archive' => [],
        'get /plans/{plan}/members' => [],
        'patch /plans/{plan}/members/{user}' => ['role' => 'admin'],
        'delete /plans/{plan}/members/{user}' => [],
        'post /plans/{plan}/invites' => ['email' => 'invitee@example.test', 'role' => 'member'],
        'get /plans/{plan}/invites' => [],
        'delete /plans/{plan}/invites/{invite}' => [],
        // size:64 is JoinPlanRequest's rule, so a shorter value answers 422 before the
        // credential is ever looked up. This route is outside the non-member and ordering
        // suites (§17.2) and the row exists so that the key set stays complete.
        'post /plans/{plan}/join' => ['token' => '0123456789abcdef0123456789abcdef0123456789abcdef0123456789abcdef'],
        'get /plans/{plan}/activity' => [],
        'get /plans/{plan}/messages' => [],
        'post /plans/{plan}/messages' => ['body' => 'Hello from the refusal matrix'],
        'patch /plans/{plan}/messages/{message}' => ['body' => 'Edited by the refusal matrix'],
        'delete /plans/{plan}/messages/{message}' => [],
    ];
}

/**
 * The request body for one Plan route, or a named failure.
 *
 * @return array<string, mixed>
 */
function planRoutePayload(string $key): array
{
    $payloads = planRoutePayloads();

    if (! array_key_exists($key, $payloads)) {
        throw new RuntimeException("No request payload is declared for [{$key}]: add one to planRoutePayloads() in tests/Pest.php.");
    }

    return $payloads[$key];
}

/**
 * The Plan every refusal suite probes: active, with an owner, one other active member, one
 * outstanding invite, and one chat message authored by the owner.
 *
 * Every path parameter the Wave 2 route table declares has a REAL, valid value here - the Plan
 * exists, {user} names an active member of it, {invite} names an invite of it, {message} names
 * a message of it - so when a non-member is refused, non-membership is the only thing left
 * that can be causing it. A fixture whose Plan did not exist would answer 404 on every row with
 * the membership check deleted, which is the vacuous shape an owner-side control exists to rule
 * out.
 *
 * The owner arrives as a parameter because an owner-side control signs one owner in and then
 * builds a fresh Plan per route: DELETE /plans/{plan} answers 204, and a shared Plan would make
 * every later row 404 for an ordering reason. $message is authored BY THE OWNER, not by
 * $activeMember, for the same reason: the owner-side control's positive-control row for
 * PATCH/DELETE .../messages/{message} needs the owner's own request to succeed, and
 * EditMessage/DeleteMessage both refuse an actor who is not the message's author regardless of
 * Plan role (see NotMessageAuthor).
 *
 * Guarantee: $plan is visible to $owner and to $activeMember, $invite belongs to $plan, and
 * $message belongs to $plan and is authored by $owner. Limit: this does not sign anyone in and
 * returns no bearer token - every caller supplies its own actor and token, which is what lets
 * the same fixture serve both a non-member suite (whose caller is a THIRD party, authenticated
 * separately) and an owner-side control.
 *
 * @return array{Plan, User, PlanInvite, PlanMessage}
 */
function refusalFixture(User $owner): array
{
    $plan = ownedPlan($owner);

    $activeMember = userWithPassword();
    member($plan, $activeMember, MemberRole::Member, '2026-01-02T00:00:00+00:00');

    $invite = PlanInvite::factory()->create([
        'plan_id' => $plan->id,
        'invited_by' => $owner->id,
    ]);

    // Field-by-field through `new PlanMessage`, not a factory: PlanMessage is $guarded = ['*']
    // (see PlanMessage's own docblock reference in the chat suite's activeMemberOf()), and this
    // feature carries no PlanMessageFactory.
    $message = new PlanMessage;
    $message->id = (string) Str::uuid7();
    $message->plan_id = $plan->id;
    $message->user_id = $owner->id;
    $message->body = 'A message the refusal suites probe.';
    $message->save();

    return [$plan, $activeMember, $invite, $message];
}

/**
 * Every call in $php to a RateLimiter FACADE method that takes a cache key, with its line.
 *
 * The seven method names below all take a cache KEY. The only keys a test could know are a named
 * limiter's name - always wrong, because ThrottleRequests stores its bucket under
 * md5($limiterName.$limit->key) (ThrottleRequests::$shouldHashKeys defaults true, and
 * ThrottleRequests::handleRequestUsingNamedLimiter() is where that key is built) while
 * RateLimiter::clear() applies no md5 at all - or LoginRateLimiter's literal
 * 'auth-login:ip:<ip>' / 'auth-login:email:<email>' keys, which are private constants of a final
 * readonly class that no test reads. So the call is forbidden outright rather than classified by
 * its argument. for() and limiter() are deliberately absent: those two take a limiter NAME, which
 * is what they are for.
 *
 * Tokens rather than a text search, measured: `grep -rn "RateLimiter::clear(" tests` reports
 * several hits, most of which are prose inside comments that exist to explain why those files do
 * NOT reset a limiter. A comment is a single T_COMMENT or T_DOC_COMMENT token, so the text inside
 * one is never the three-token sequence <name> :: <method> and needs no special case; those two
 * token types are dropped here, along with T_WHITESPACE, so that a comment or a newline written
 * BETWEEN the three tokens cannot hide a real call either.
 *
 * The name is matched on its last segment, so RateLimiter::, \RateLimiter:: (the root facade
 * alias - six of eight such aliases evaded this project's name-based arch rule, which is why
 * phpstan-rules/src/DomainPurity exists) and Illuminate\Support\Facades\RateLimiter:: are all one
 * call, while LoginRateLimiter:: is not.
 *
 * The method name is matched case-insensitively on both sides (strtolower()), because PHP method
 * dispatch is case-insensitive while `in_array(..., true)` is not: RateLimiter::Clear() is a
 * working call that a case-sensitive comparison would miss outright.
 *
 * @return list<string>
 */
function rateLimiterKeyCallsIn(string $php): array
{
    $forbidden = ['attempt', 'attempts', 'availablein', 'clear', 'hit', 'remaining', 'toomanyattempts'];

    $tokens = array_values(array_filter(
        token_get_all($php),
        fn (array|string $token): bool => is_string($token)
            || ! in_array($token[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true),
    ));

    $calls = [];
    $total = count($tokens);

    for ($i = 0; $i + 2 < $total; $i++) {
        $name = $tokens[$i];
        $colons = $tokens[$i + 1];
        $method = $tokens[$i + 2];

        // A single-character token - '(', ';', '\\' and so on - arrives as a plain string with
        // no type and no line, so none of the three positions can be one.
        if (is_string($name) || is_string($colons) || is_string($method)) {
            continue;
        }

        if (! in_array($name[0], [T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED], true)) {
            continue;
        }

        if ($colons[0] !== T_DOUBLE_COLON || $method[0] !== T_STRING) {
            continue;
        }

        $segments = explode('\\', ltrim($name[1], '\\'));

        if (end($segments) !== 'RateLimiter' || ! in_array(strtolower($method[1]), $forbidden, true)) {
            continue;
        }

        $calls[] = 'RateLimiter::'.$method[1].'() on line '.$name[2];
    }

    return $calls;
}
