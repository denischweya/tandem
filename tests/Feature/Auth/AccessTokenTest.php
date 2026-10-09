<?php

declare(strict_types=1);

use App\Models\PersonalAccessToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

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
    /** @var TestCase $this */
    $user = User::factory()->create();
    $token = $user->createToken('mobile', ['*'], now()->addMinutes(15));

    $this->withToken($token->plainTextToken)
        ->getJson('/__sanctum-probe')
        ->assertOk()
        ->assertJson(['id' => $user->id]);
});

it('rejects a token whose own expires_at has passed', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $token = $user->createToken('mobile', ['*'], now()->subSecond());

    $this->withToken($token->plainTextToken)
        ->getJson('/__sanctum-probe')
        ->assertUnauthorized();
});

it('rejects a token belonging to a soft-deleted user', function (): void {
    /** @var TestCase $this */
    $user = User::factory()->create();
    $token = $user->createToken('mobile', ['*'], now()->addMinutes(15));

    $user->delete();

    $this->withToken($token->plainTextToken)
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
