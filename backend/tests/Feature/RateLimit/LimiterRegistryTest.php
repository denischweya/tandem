<?php

declare(strict_types=1);

use App\Models\Plan;
use App\Models\User;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

/**
 * What no 429 can show.
 *
 * Every exhaustion test in this suite proves that a 429 arrives after N requests. None of them
 * can see four things, and all four have been wrong in this project before.
 *
 * (1) The WINDOW. Limit::perHour(30) and Limit::perMinute(30) are indistinguishable to a test
 *     that fires 31 requests in one process, because no window of any length expires inside
 *     one test. The same holds for perDay(50) against perHour(50), and for the two Wave 1a
 *     limiters' own perHour windows.
 *
 * (2) The KEY. Spec §13.1 states it outright: a limiter keyed on the wrong thing produces the
 *     same 429 as one keyed on the right thing, so no status assertion can see it. The
 *     behavioural proof - exhaust one user, then show a second user on the same IP is
 *     unaffected - is real and lives with the route it belongs to, but it is indirect: it shows
 *     two keys differ without naming either, and it only works because every request in this
 *     suite arrives with REMOTE_ADDR = 127.0.0.1.
 *
 * (3) The SET of throttled routes, and what a route's middleware ACTUALLY resolves to once
 *     named groups are expanded. §13.4 says POST /plans/{plan}/join carries no limit and that
 *     nothing else in Wave 2 is limited either. Prose cannot be regressed against, and neither
 *     can a collection built from Route::gatherMiddleware() alone: that method returns a
 *     route's OWN middleware array unexpanded - ['api', 'auth:sanctum', ...] - so a throttle:
 *     entry injected into the 'api' group itself (which every api/v1 route, including the join
 *     route, carries) would be invisible to it while still applying. This file collects from
 *     the booted Router's gatherRouteMiddleware() instead, which is what the Kernel actually
 *     dispatches through, and asserts the 'api' group's own contents directly rather than
 *     assuming them.
 *
 * (4) The GUARD shape on a route parameter or a request field read before any FormRequest has
 *     validated it. Wave 1a's auth-forgot-password limiter already paid for this once - an
 *     array `email` passed route dispatch and a bare (string) cast emitted an E_WARNING the
 *     kernel promotes to an ErrorException INSIDE the closure, a reachable 500 on a public
 *     endpoint, before ForgotPasswordRequest's own 'string' rule ever got a chance to answer
 *     422. plan-invites' route-parameter guard exists for the identical reason. Both Wave 1a
 *     limiters are asserted here by name, not only listed in the route-table literal, because a
 *     six-row literal that only NAMES a limiter proves nothing about what is inside it.
 *
 * So this file invokes the registered closures directly and compares the route table against an
 * exact literal. Each of key, maxAttempts and decaySeconds is its own expectation, so a break
 * produces a one-line diff rather than an array diff containing a per-run uuid.
 *
 * No RateLimiter::clear() here or anywhere under tests/: the middleware's real bucket is
 * md5($limiterName.$limit->key) (ThrottleRequests::$shouldHashKeys and
 * handleRequestUsingNamedLimiter()), so the bare name clears nothing, and nothing is needed in
 * its place because phpunit.xml pins CACHE_STORE=array and the application is rebuilt per test.
 * RateLimiter::limiter() below is the other thing entirely: it takes a limiter NAME, which is
 * what it is for. tests/Feature/RateLimit/LimiterKeyHygieneTest.php is the gate on that
 * distinction.
 */
it('throttles exactly six routes, and every limiter they name is registered', function (): void {
    $router = app('router');

    // Route::getRoutes() returns the RouteCollection; the collection's own getRoutes() returns
    // the Route[]. The double call is not a typo - the interface the facade is typed as is not
    // Traversable, so the collection cannot be foreach'd directly.
    $throttled = [];
    $allUris = [];

    foreach (Route::getRoutes()->getRoutes() as $route) {
        $allUris[] = $route->uri();

        // gatherRouteMiddleware(), not gatherMiddleware(): the latter is blind to a named
        // group's own middleware (see the docblock above). The resolved form names a
        // middleware by its FULLY QUALIFIED CLASS, so a parameterised entry reads
        // 'Illuminate\Routing\Middleware\ThrottleRequests:plans-create', not
        // 'throttle:plans-create' - both spellings are matched below so neither the raw alias
        // form (if some other code path ever produced one) nor the resolved form is missed.
        foreach ($router->gatherRouteMiddleware($route) as $middleware) {
            // A route may legitimately carry a middleware object or a closure, so is_string()
            // first: str_starts_with() on one of those is a TypeError, not a skipped entry.
            if (! is_string($middleware)) {
                continue;
            }

            $limiterName = match (true) {
                str_starts_with($middleware, 'throttle:') => substr($middleware, strlen('throttle:')),
                str_starts_with($middleware, ThrottleRequests::class.':') => substr($middleware, strlen(ThrottleRequests::class.':')),
                default => null,
            };

            if ($limiterName === null) {
                continue;
            }

            $throttled[] = [
                // Named, or the uri - so an unnamed route appears in the diff below instead of
                // colliding on an empty string.
                $route->getName() ?? '(unnamed) '.$route->uri(),
                $route->uri(),
                $limiterName,
            ];
        }
    }

    sort($throttled);

    // The 'api' group's contents, asserted directly rather than assumed: this is what makes
    // "bootstrap/app.php calls neither throttleApi() nor statefulApi()" a checked fact instead
    // of prose. If a future change ever adds $middleware->throttleApi(), this group gains a
    // 'throttle:api' entry (or its resolved equivalent) BEFORE SubstituteBindings, and the
    // literal below goes red - which is also the thing that would otherwise make every api/v1
    // route, including the join route, silently throttled while $throttledUris stayed
    // unchanged under a gatherMiddleware()-only collection.
    expect($router->getMiddlewareGroups()['api'])->toBe(
        [SubstituteBindings::class],
        "the 'api' group carries exactly one middleware; a throttle: entry here would cap every api/v1 route and make §13.4's claims false while staying invisible to a route-level collection",
    );

    $throttledUris = [];

    foreach ($throttled as [, $uri]) {
        $throttledUris[] = $uri;
    }

    // §13.4 first, deliberately, so that its own failure is the one Pest reports rather than a
    // consequence of the literal below. POST /plans/{plan}/join carries no limit: the caller
    // must hold a 64-hex single-use invite, so there is nothing to brute-force and nothing a
    // second request would gain, and issuance is already limited by §13.2 - the same reasoning
    // /auth/reset-password records for the same token shape.
    //
    // Two halves, because "join is not in the throttled set" would pass if the join route did
    // not exist at all, which is the one way this could read as load-bearing while checking
    // nothing. Read by uri rather than by route name so this does not depend on another
    // section's naming.
    expect($allUris)->toContain('api/v1/plans/{plan}/join');
    expect($throttledUris)->not->toContain('api/v1/plans/{plan}/join');

    // Spec §8.2's two Wave 1a rows and §13's four Wave 2 rows (chat-send is the chat feature's
    // own addition to §13's set, not a sixth thing outside it - it is the same "limit the write,
    // not the read" shape as plans-create and plan-invites).
    //
    // A SEVENTH ROW HERE IS NOT A TEST FAILURE TO BE FIXED BY EDITING THIS LIST. It means a rate
    // limit landed without a §13 decision; amend §13 first, then this literal.
    expect($throttled)->toBe([
        ['auth.forgot-password', 'api/v1/auth/forgot-password', 'auth-forgot-password'],
        ['auth.register', 'api/v1/auth/register', 'auth-register'],
        ['invites.preview', 'api/v1/invites/{token}', 'invite-lookup'],
        ['plans.invites.store', 'api/v1/plans/{plan}/invites', 'plan-invites'],
        ['plans.messages.store', 'api/v1/plans/{plan}/messages', 'chat-send'],
        ['plans.store', 'api/v1/plans', 'plans-create'],
    ], 'spec §8.2 and §13: six throttled routes in the whole API, four of the limiters new since Wave 1a');

    // A route may name a limiter nobody registered. Laravel's answer is
    // MissingRateLimiterException - "Rate limiter [<name>] is not defined." from
    // ThrottleRequests::resolveMaxAttempts(), reached because handle() takes the named-limiter
    // path only when RateLimiter::limiter($name) is non-null. That is a 500 on that one route
    // and nowhere else, so it stays invisible until something exercises it.
    $unregistered = [];

    foreach ($throttled as [$name, , $limiterName]) {
        if (RateLimiter::limiter($limiterName) === null) {
            $unregistered[] = $name.' names throttle:'.$limiterName;
        }
    }

    expect($unregistered)->toBe([], 'every throttle: on the route table resolves to a RateLimiter::for()');
});

it('keys plans-create on the authenticated user, thirty per hour', function (): void {
    $user = User::factory()->create();

    // 198.51.100.7 is RFC 5737 documentation space and appears in no assertion below: that is
    // the point. An IP-keyed closure passes every exhaustion test in this suite, because the
    // test environment gives every request REMOTE_ADDR = 127.0.0.1 and one user per test.
    $request = Request::create('/api/v1/plans', 'POST', server: ['REMOTE_ADDR' => '198.51.100.7']);
    $request->setUserResolver(fn () => $user);

    $closure = RateLimiter::limiter('plans-create');

    // A real instanceof rather than an inline @var: RateLimiter::limiter() is declared
    // `\Closure|null`, this project's level-8 constraint forbids an inferred-type override, and
    // a null here means the limiter was never registered - which should say so by name instead
    // of surfacing as "invoking a null value" two lines down.
    if (! $closure instanceof Closure) {
        throw new RuntimeException("No limiter is registered under the name 'plans-create'.");
    }

    $limit = $closure($request);

    if (! $limit instanceof Limit) {
        throw new RuntimeException('The plans-create limiter returned something other than one Limit.');
    }

    // The shipped closure reads $request->user()?->getAuthIdentifier(), not ?->id - measured,
    // not merely the documented fallback: PHPStan at level 8 flags a nullsafe PROPERTY fetch at
    // the leaf of a ?? chain as nullsafe.neverNull regardless of actual nullability, and a
    // nullsafe METHOD call in the same position does not. getAuthIdentifier() returns
    // $this->{getAuthIdentifierName()}, which for App\Models\User is the same uuid $this->id
    // would give, so this assertion is unaffected by which form the closure uses.
    expect($limit->key)->toBe($user->getAuthIdentifier(), 'spec §13.1: keyed on the user id, because the limit is per user');
    expect($limit->maxAttempts)->toBe(30, 'spec §13.1: thirty');
    // 3600, not 60: perHour(30) and perMinute(30) are indistinguishable to any exhaustion test,
    // because neither window expires inside one test.
    expect($limit->decaySeconds)->toBe(3600, 'spec §13.1: per HOUR - 60 here would be per minute and no 429 test could tell');
});

it('falls back to the IP and then to the literal unknown when nobody is authenticated', function (): void {
    $closure = RateLimiter::limiter('plans-create');

    if (! $closure instanceof Closure) {
        throw new RuntimeException("No limiter is registered under the name 'plans-create'.");
    }

    $byIp = Request::create('/api/v1/plans', 'POST', server: ['REMOTE_ADDR' => '198.51.100.7']);
    // Set explicitly rather than left to the default, so the test states what it is driving.
    $byIp->setUserResolver(fn () => null);

    $ipLimit = $closure($byIp);

    if (! $ipLimit instanceof Limit) {
        throw new RuntimeException('The plans-create limiter returned something other than one Limit.');
    }

    expect($ipLimit->key)->toBe('198.51.100.7');

    // Request::ip() is declared ?string and returns null when REMOTE_ADDR is absent - not
    // reachable over HTTP, but reachable from a console command or a queued job that builds a
    // request. Without the `?? 'unknown'` the key would be null, which the middleware hashes as
    // md5('plans-create'), giving every such caller one shared bucket.
    $anonymous = Request::create('/api/v1/plans', 'POST');
    $anonymous->server->remove('REMOTE_ADDR');
    $anonymous->setUserResolver(fn () => null);

    $unknownLimit = $closure($anonymous);

    if (! $unknownLimit instanceof Limit) {
        throw new RuntimeException('The plans-create limiter returned something other than one Limit.');
    }

    expect($unknownLimit->key)->toBe('unknown');
});

it('returns two limits for plan-invites: fifty a day by user, twenty an hour by plan', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->create();

    $request = Request::create("/api/v1/plans/{$plan->id}/invites", 'POST', server: ['REMOTE_ADDR' => '198.51.100.7']);
    $request->setUserResolver(fn () => $user);

    // match() is what BINDS the route parameters to the request. Without it
    // Route::parameters() throws LogicException('Route is not bound.'), and
    // $request->route('plan') reads Route::parameter(), which reads parameters() - not
    // originalParameters().
    $route = Route::getRoutes()->match($request);
    $request->setRouteResolver(fn () => $route);

    $closure = RateLimiter::limiter('plan-invites');

    if (! $closure instanceof Closure) {
        throw new RuntimeException("No limiter is registered under the name 'plan-invites'.");
    }

    $limits = $closure($request);

    if (! is_array($limits) || count($limits) !== 2) {
        throw new RuntimeException('The plan-invites limiter did not return exactly two limits.');
    }

    [$byUser, $byPlan] = $limits;

    if (! $byUser instanceof Limit || ! $byPlan instanceof Limit) {
        throw new RuntimeException('The plan-invites limiter returned something other than two Limit objects.');
    }

    // Two limits from ONE named limiter, because RateLimiter::for() accepts an array and
    // handleRequestUsingNamedLimiter() does Collection::wrap($limiterResponse)->map(...). The
    // order is for the reader, not for the middleware: handleRequest() checks every limit
    // before it hits any limit, so which one answers is decided by which bucket is full, and
    // the 429 from the per-plan limit does not increment the per-user bucket.
    expect($byUser->key)->toBe('user:'.$user->getAuthIdentifier(), 'spec §13.2: the per-user bucket, prefixed so it cannot collide with the per-plan one');
    expect($byUser->maxAttempts)->toBe(50);
    expect($byUser->decaySeconds)->toBe(86400, 'spec §13.2: per DAY - 3600 here would be per hour and no 429 test could tell');

    expect($byPlan->key)->toBe('plan:'.$plan->id, 'spec §13.2: the per-plan bucket, keyed on the route parameter');
    expect($byPlan->maxAttempts)->toBe(20);
    expect($byPlan->decaySeconds)->toBe(3600);
});

it('keys plan-invites on plan:unknown when the route parameter is not a string', function (): void {
    $user = User::factory()->create();
    $plan = Plan::factory()->create();

    $closure = RateLimiter::limiter('plan-invites');

    if (! $closure instanceof Closure) {
        throw new RuntimeException("No limiter is registered under the name 'plan-invites'.");
    }

    // One inline closure rather than three copies of the same narrowing. Not promoted to
    // tests/Pest.php: this suite already carries many process-global helper functions across
    // tests/Pest.php and several other test files (Pest loads every test file into the global
    // namespace, so a second declaration of any shared name is a fatal "Cannot redeclare") -
    // grep for a name before adding it there, rather than trust a count written in a comment,
    // because that count is exactly the kind of figure this wave has repeatedly found stale.
    $keyFor = function (Closure $limiter, Request $request, int $index): string {
        $limits = $limiter($request);
        $limit = is_array($limits) ? ($limits[$index] ?? null) : null;

        if (! $limit instanceof Limit || ! is_string($limit->key)) {
            throw new RuntimeException("The plan-invites limiter returned no Limit with a string key at index {$index}.");
        }

        return $limit->key;
    };

    // Illuminate\Routing\Route::parameter() is declared `string|object|null`, so
    // $request->route('plan') is not guaranteed to be a string. Today SubstituteBindings sorts
    // AFTER ThrottleRequests (measured directly against vendor/laravel/framework's own
    // $middlewarePriority and SortedMiddleware algorithm, not merely cited: for
    // ['api','auth:sanctum','verified','throttle:plan-invites'] the resolved order is
    // Authenticate:sanctum, ThrottleRequests:plan-invites, SubstituteBindings,
    // EnsureEmailIsVerified), so the closure sees the raw string. The guard is for the other
    // case, and the precedent is expensive: AppServiceProvider's forgot-password limiter
    // originally did (string) $request->input('email'), and an array value made that an
    // E_WARNING, an ErrorException and a reachable 500 on a public endpoint, because route
    // middleware runs before the FormRequest has validated anything.
    //
    // A bare (string) cast here would NOT throw either: Model::__toString() is toJson() and
    // $escapeWhenCastingToString is false, so the cast's failure mode is quieter and worse than
    // a 500 - the bucket key becomes the Plan's whole serialised state, which changes whenever
    // any attribute does, so the per-plan limit silently stops being per-plan. (Measured
    // separately: PHPStan at level 8, with this project's installed phpstan/phpstan and
    // larastan, does not reject either 'plan:'.$plan or 'plan:'.(string) $plan for a value typed
    // string|object|null from Route::parameter() - both pass static analysis clean, so the
    // is_string() guard here is purely a runtime concern, not one static analysis also catches.)
    $withModel = Request::create("/api/v1/plans/{$plan->id}/invites", 'POST', server: ['REMOTE_ADDR' => '198.51.100.7']);
    $withModel->setUserResolver(fn () => $user);

    $modelRoute = Route::getRoutes()->match($withModel);
    $modelRoute->setParameter('plan', $plan);
    $withModel->setRouteResolver(fn () => $modelRoute);

    expect($keyFor($closure, $withModel, 1))->toBe('plan:unknown');

    // The per-user key from the same closure, so the assertion above cannot be passing because
    // the whole closure fell over and returned something degenerate.
    expect($keyFor($closure, $withModel, 0))->toBe('user:'.$user->getAuthIdentifier());

    // The third member of `string|object|null`: no parameter at all, which is what a
    // throttle:plan-invites entry on a route with no {plan} segment would produce. 'plan:unknown'
    // again, and NOT 'plan:' - which is exactly what dropping the
    // `is_string($plan) ? $plan : 'unknown'` guard gives for a null parameter, one shared bucket
    // for every Plan in the system.
    $withNothing = Request::create("/api/v1/plans/{$plan->id}/invites", 'POST', server: ['REMOTE_ADDR' => '198.51.100.7']);
    $withNothing->setUserResolver(fn () => $user);

    $emptyRoute = Route::getRoutes()->match($withNothing);
    $emptyRoute->forgetParameter('plan');
    $withNothing->setRouteResolver(fn () => $emptyRoute);

    expect($keyFor($closure, $withNothing, 1))->toBe('plan:unknown');
});

it('keys invite-lookup on the IP, sixty per hour', function (): void {
    $closure = RateLimiter::limiter('invite-lookup');

    if (! $closure instanceof Closure) {
        throw new RuntimeException("No limiter is registered under the name 'invite-lookup'.");
    }

    // No user resolver and no route resolver: GET /invites/{token} is unauthenticated and the
    // closure reads neither. Request::getUserResolver() already returns a closure yielding null
    // when none was set, so user() is null here without being told to be.
    //
    // The route is not matched either, deliberately - this closure never calls route(), so
    // matching would couple the test to the Invitations section's uri for nothing. The 64
    // hex characters in the path are cosmetic and only make the request look like a real one.
    $request = Request::create(
        '/api/v1/invites/'.str_repeat('a', 64),
        'GET',
        server: ['REMOTE_ADDR' => '198.51.100.7'],
    );

    $limit = $closure($request);

    if (! $limit instanceof Limit) {
        throw new RuntimeException('The invite-lookup limiter returned something other than one Limit.');
    }

    // Keyed on the IP and not on the token: §13.3's limit does not protect the token - 256 bits
    // is not guessable, which is the same reason /auth/reset-password carries no throttle at all
    // - it bounds scraping with a harvested list of links, and a per-token key would let one
    // host walk such a list unimpeded.
    expect($limit->key)->toBe('198.51.100.7', 'spec §13.3: keyed on the IP, not on the token');
    expect($limit->maxAttempts)->toBe(60, 'spec §13.3: sixty, which leaves a household behind one NAT comfortably clear');
    expect($limit->decaySeconds)->toBe(3600, 'spec §13.3: per HOUR - 60 here would be per minute and no 429 test could tell');
});

it('keys auth-register on the IP, five per hour', function (): void {
    // Wave 1a's own limiter, asserted here by name and not only listed in the route-table
    // literal above - a literal that names a limiter proves nothing about what the closure
    // actually does with a request. §8.2: 5 per hour per IP.
    $closure = RateLimiter::limiter('auth-register');

    if (! $closure instanceof Closure) {
        throw new RuntimeException("No limiter is registered under the name 'auth-register'.");
    }

    $request = Request::create('/api/v1/auth/register', 'POST', server: ['REMOTE_ADDR' => '198.51.100.7']);

    $limit = $closure($request);

    if (! $limit instanceof Limit) {
        throw new RuntimeException('The auth-register limiter returned something other than one Limit.');
    }

    expect($limit->key)->toBe('198.51.100.7', 'spec §8.2: keyed on the IP');
    expect($limit->maxAttempts)->toBe(5, 'spec §8.2: five');
    expect($limit->decaySeconds)->toBe(3600, 'spec §8.2: per HOUR - 60 here would be per minute and no 429 test could tell');
});

it('keys auth-forgot-password on the normalized email, three per hour, and survives a non-string email', function (): void {
    // Wave 1a's other limiter. Its is_string() guard is the one with a recorded, expensive
    // history: an array `email` once passed route dispatch and a bare (string) cast emitted an
    // E_WARNING the kernel promotes to an ErrorException INSIDE this closure - a reachable 500
    // on a public, unauthenticated endpoint, before ForgotPasswordRequest's own 'string' rule
    // ever got a chance to answer 422. The six-row route-table literal above names this
    // limiter but asserts nothing about its key, window or guard - this block closes that gap.
    $closure = RateLimiter::limiter('auth-forgot-password');

    if (! $closure instanceof Closure) {
        throw new RuntimeException("No limiter is registered under the name 'auth-forgot-password'.");
    }

    // The casing and the surrounding whitespace are deliberate: the key must agree with
    // ForgotPasswordRequest's own normalisation (EmailInput::normalize(), shared by both) or the
    // 3-per-hour-per-email limit is bypassable by casing alone.
    $withEmail = Request::create('/api/v1/auth/forgot-password', 'POST', ['email' => ' USER@Example.COM ']);

    $limit = $closure($withEmail);

    if (! $limit instanceof Limit) {
        throw new RuntimeException('The auth-forgot-password limiter returned something other than one Limit.');
    }

    expect($limit->key)->toBe('email:user@example.com', 'spec §8.2: keyed on the normalized email');
    expect($limit->maxAttempts)->toBe(3, 'spec §8.2: three');
    expect($limit->decaySeconds)->toBe(3600, 'spec §8.2: per HOUR - 60 here would be per minute and no 429 test could tell');

    // The guard: a non-string `email` must not reach EmailInput::normalize(), which declares a
    // native `string` parameter and would TypeError on anything else. Guarded with
    // is_string($email) ? $email : '', so the fallback is the empty string, not a crash - and
    // EmailInput::normalize('') is '', so the bucket key degrades to the shared 'email:' rather
    // than throwing. That shared bucket is a known, accepted cost (every non-string caller
    // shares one budget) and is a different, smaller problem than a 500.
    $withArrayEmail = Request::create('/api/v1/auth/forgot-password', 'POST', ['email' => ['not', 'a', 'string']]);

    $guardedLimit = $closure($withArrayEmail);

    if (! $guardedLimit instanceof Limit) {
        throw new RuntimeException('The auth-forgot-password limiter returned something other than one Limit for a non-string email.');
    }

    expect($guardedLimit->key)->toBe('email:', 'the is_string() guard falls back to the empty string rather than crashing on an array email');
});
