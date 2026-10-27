<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use function Pest\Laravel\actingAs;
use function Pest\Laravel\getJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    // A probe route, not a product route - this task adds no endpoint. The real auth routes
    // arrive in Task 3 onward, but `auth:sanctum` cannot be exercised without something
    // behind it, and Sanctum::actingAs() would bypass the exact code path under test:
    // findToken()'s uuid primary-key lookup and the per-token expires_at check.
    Route::middleware(['api', 'auth:sanctum'])->get('/__sanctum-probe', function (Request $request): array {
        return ['id' => (string) $request->user()?->getAuthIdentifier()];
    });
});

it('issues a token whose primary key is a UUIDv7, not an integer', function (): void {
    $user = User::factory()->create();

    $token = $user->createToken('mobile', ['*'], now()->addMinutes(15));

    expect($token->accessToken)->toBeInstanceOf(PersonalAccessToken::class);
    expect($token->accessToken->getKey())
        ->toBeString()
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('authenticates a bearer request with a live token', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('mobile', ['*'], now()->addMinutes(15));

    withToken($token->plainTextToken)
        ->getJson('/__sanctum-probe')
        ->assertOk()
        ->assertJson(['id' => $user->id]);
});

it('rejects a token whose own expires_at has passed', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('mobile', ['*'], now()->subSecond());

    withToken($token->plainTextToken)
        ->getJson('/__sanctum-probe')
        ->assertUnauthorized();
});

it('rejects a token belonging to a soft-deleted user', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('mobile', ['*'], now()->addMinutes(15));

    $user->delete();

    withToken($token->plainTextToken)
        ->getJson('/__sanctum-probe')
        ->assertUnauthorized();
});

it('stores only a hash of the token, never the plaintext', function (): void {
    $user = User::factory()->create();
    $token = $user->createToken('mobile', ['*'], now()->addMinutes(15));

    [, $plain] = explode('|', $token->plainTextToken, 2);

    expect($token->accessToken->token)->toBe(hash('sha256', $plain));
    expect($token->accessToken->token)->not->toContain($plain);
});

it('refuses a session-authenticated caller with no bearer token', function (): void {
    // Spec §9.2 / ADR 0004: the API speaks only bearer tokens, and no second authentication
    // path exists. That was a claim about intent until now. Sanctum's Guard iterates
    // config('sanctum.guard') BEFORE looking at the bearer token, and the vendor default is
    // ['web'] - so acting as a user on the session guard and calling an auth:sanctum route
    // with NO Authorization header returned 200. Measured, then closed in config/sanctum.php.
    //
    // It was inert in production only because the `api` middleware group has no StartSession,
    // which is an accident one middleware edit away from being false, and which nothing
    // asserted. This asserts the thing the spec actually claims instead.
    $user = User::factory()->hasProfile()->create();

    actingAs($user, 'web');

    // The behaviour first, deliberately: an assertion on config('sanctum.guard') placed above
    // this one short-circuits the test, so every mutation fails on the config value and the
    // 401 is never exercised. The 401 is the claim; the config value is the mechanism.
    getJson('/api/v1/me')->assertUnauthorized();

    expect(config('sanctum.guard'))->toBe(
        [],
        'Laravel\Sanctum\Guard::__invoke() iterates this list before reading the bearer token, '
        .'so any entry here is a second authentication path.',
    );
});
