<?php

declare(strict_types=1);

use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

/**
 * Spec §17.2 suite 1: for every Plan route that names a Plan, a signed-in user who is not a
 * member of that Plan gets 404 and the one PlanNotVisible body - never 403, never 200, never
 * 500.
 *
 * Enumerated off Laravel's route table, not off a list written here, so a Plan route added
 * without any refusal coverage fails this file instead of being skipped by it. The devices
 * that stop that derivation from passing vacuously, all three of which have a break step below:
 *
 *   1. planRouteKeys() is compared against a hand-written list of fourteen. The derived list
 *      and the literal cannot be emptied together.
 *   2. Every loop increments $checked and every test ends with expect($checked)->toBe(n). A
 *      filter that matches nothing fails here, loudly, naming the number it expected.
 *   3. The owner-side control asserts the SAME eleven routes answer 200/201/204 for the Plan's
 *      owner. Without it, a fixture whose Plan did not exist would satisfy the non-member suite
 *      with the membership check deleted.
 *
 * Bodies are compared as strings, never with assertJsonPath: a closure's JsonResponse is
 * compact and Laravel's own 404s are pretty-printed, and the whole point of §5.1's rejection of
 * Route::whereUuid is that one route must not answer two bodies. See
 * tests/Feature/Security/RefusalSeamTest.php's docblock.
 *
 * Neither test calls forgetAuthenticatedUser(), and that is a decision rather than an
 * omission: each one uses a single bearer token for every request it makes, so the user
 * Sanctum's guard caches on the first request is the right user on all the rest. A second actor
 * added to either test needs the call added with it.
 */
it('accounts for every api/v1 route as Wave 1a, a Plan route, or a named exemption', function (): void {
    // Spec §8's fourteen Plan routes, as documentedRoutes() spells them. Sorted ascending,
    // which is the order planRouteKeys() returns.
    $expected = [
        'delete /plans/{plan}',
        'delete /plans/{plan}/invites/{invite}',
        'delete /plans/{plan}/members/{user}',
        'get /plans',
        'get /plans/{plan}',
        'get /plans/{plan}/activity',
        'get /plans/{plan}/invites',
        'get /plans/{plan}/members',
        'patch /plans/{plan}',
        'patch /plans/{plan}/members/{user}',
        'post /plans',
        'post /plans/{plan}/archive',
        'post /plans/{plan}/invites',
        'post /plans/{plan}/join',
    ];

    // Both directions at once: a Plan route missing from the matrix fails, and a matrix entry
    // for a route that no longer exists fails. The count is the one assertion a simultaneous
    // emptying of both sides cannot satisfy - the same shape as the envelope/bodyless pair
    // counter in tests/Feature/Contract/OpenApiContractTest.php.
    expect(planRouteKeys())->toBe($expected);
    expect(planRouteKeys())->toHaveCount(14);

    // A payload row per route, checked here rather than discovered as a RuntimeException in
    // the middle of a loop three tests later.
    $payloadKeys = array_keys(planRoutePayloads());
    sort($payloadKeys);

    expect($payloadKeys)->toBe($expected);

    // The join route is outside the non-member and owner-control loops below (it is exempt from
    // both, per §10.3's "the invite token is the credential"), asserted to exist here so that
    // renaming the route turns the exemption into a loud failure rather than a silent widening.
    expect(planRouteKeys())->toContain('post /plans/{plan}/join');

    // Wave 1a's ten documented operations over eight paths.
    $waveOne = [
        'post /auth/register',
        'post /auth/login',
        'post /auth/verify-email',
        'post /auth/refresh',
        'post /auth/logout',
        'post /auth/forgot-password',
        'post /auth/reset-password',
        'get /me',
        'patch /me',
        'delete /me',
    ];

    // §10.3's invite preview is reached by people who are members of nothing, so it cannot sit
    // in a non-member-404 matrix: every failure there answers the one InviteNotUsable body
    // instead (§17.2's closing paragraph), covered by tests/Feature/Plans/PreviewInviteTest.php.
    $exempt = [
        'get /invites/{token}',
    ];

    // The blind spot this closes: planRouteTable() only sees routes under /plans, so a route
    // registered anywhere else under api/v1 would escape every suite built on it silently. Here
    // it cannot - it has to be a Wave 1a row, a Plan row, or an exemption with a reason written
    // beside it.
    $all = array_map(fn (array $row): string => $row[0].' '.$row[1], documentedRoutes());
    sort($all);

    $accounted = [...$waveOne, ...$exempt, ...planRouteKeys()];
    sort($accounted);

    expect($all)->toBe($accounted);
});

it('declares no route pattern on any Plan route', function (): void {
    $checked = 0;

    foreach (planRouteTable() as [$method, $path, $route]) {
        // Spec §5.1 rejects Route::whereUuid('plan') as a mechanism, not as a preference: the
        // router's own NotFoundHttpException renders pretty-printed and quotes the request
        // path, so one route would answer two different 404 bodies depending on whether the id
        // merely LOOKED like a uuid. Validating the shape inside the repository gives exactly
        // one body.
        //
        // Global patterns are merged into each route's own array by the router when the route
        // table is built, so this also catches a Route::pattern('plan', ...) declared once for
        // the whole application, not only a per-route whereUuid.
        expect($route->wheres)->toBe([], "{$method} {$path} must declare no where()/whereUuid() pattern (§5.1)");

        $checked++;
    }

    expect($checked)->toBe(14);
});

it('answers 404 with the one Plan-not-found body on every Plan route a non-member can reach', function (): void {
    $owner = userWithPassword();
    [$plan, $activeMember, $invite] = refusalFixture($owner);

    // A verified account with a profile, signed in through the real endpoint, that has no
    // plan_members row for this Plan or any other. UserFactory's definition sets
    // email_verified_at = now(), which matters because every Plan route carries `verified`.
    [, $outsiderAccess] = authenticatedUser();

    $checked = 0;

    foreach (planRouteTable() as [$method, $path]) {
        if (! str_contains($path, '{plan}') || $path === '/plans/{plan}/join') {
            continue;
        }

        $key = $method.' '.$path;

        // Every second parameter names a real row OF THIS PLAN, so non-membership is the only
        // thing that can be causing the refusal.
        $uri = planRouteUri($path, [
            'plan' => $plan->id,
            'user' => $activeMember->id,
            'invite' => $invite->id,
        ]);

        $response = withToken($outsiderAccess)->json(strtoupper($method), $uri, planRoutePayload($key));

        expect($response->getStatusCode())->toBe(404, "{$key} must answer 404 for a non-member, not 403 and not 200");
        expect($response->getContent())->toBe('{"message":"Plan not found."}', "{$key} must answer the one PlanNotVisible body for a non-member");

        $checked++;
    }

    // Fourteen Plan routes, minus `get /plans` and `post /plans` (no {plan} to be a non-member
    // of) and minus `post /plans/{plan}/join` (the exemption above) = eleven.
    expect($checked)->toBe(11);
});

it('answers 200, 201 or 204 on the same routes for the Plan owner', function (): void {
    $owner = userWithPassword();

    [, , $ownerAccess] = signIn($owner);

    $checked = 0;

    foreach (planRouteTable() as [$method, $path]) {
        if (! str_contains($path, '{plan}') || $path === '/plans/{plan}/join') {
            continue;
        }

        $key = $method.' '.$path;

        // A fresh Plan per route, because the row for `delete /plans/{plan}` answers 204 and
        // takes the Plan with it, and `post /plans/{plan}/archive` leaves an archived Plan that
        // answers 409 to three of the other rows.
        [$plan, $activeMember, $invite] = refusalFixture($owner);

        $uri = planRouteUri($path, [
            'plan' => $plan->id,
            'user' => $activeMember->id,
            'invite' => $invite->id,
        ]);

        $response = withToken($ownerAccess)->json(strtoupper($method), $uri, planRoutePayload($key));

        // Not `not->toBe(404)`: spec §5.4 says every successful Wave 2 answer is 200, 201 or
        // 204, and a 403 or a 422 here would mean the fixture is wrong in a way the suite above
        // cannot see. in_array() inside the expectation follows this wave's own house form for
        // a status drawn from a small fixed set (see the envelope-schema check in
        // tests/Feature/Contract/OpenApiContractTest.php).
        expect(in_array($response->getStatusCode(), [200, 201, 204], true))
            ->toBeTrue("{$key} must answer 200, 201 or 204 for the Plan's owner, not {$response->getStatusCode()} - the suite above proves nothing unless this one passes");

        $checked++;
    }

    expect($checked)->toBe(11);
});
