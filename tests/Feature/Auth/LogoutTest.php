<?php

declare(strict_types=1);

use App\Models\RefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

it('revokes the refresh token and deletes the access token', function (): void {
    [$user, $refresh, $access] = signIn();

    withToken($access)
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $refresh])
        ->assertNoContent();

    expect(RefreshToken::where('token_hash', hash('sha256', $refresh))->sole()->revoked_at)->not->toBeNull();
    expect($user->tokens()->count())->toBe(0);
});

it('makes the revoked refresh token unusable', function (): void {
    [, $refresh, $access] = signIn();

    withToken($access)->postJson('/api/v1/auth/logout', ['refresh_token' => $refresh])->assertNoContent();

    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
});

it('requires authentication', function (): void {
    [, $refresh] = signIn();

    postJson('/api/v1/auth/logout', ['refresh_token' => $refresh])->assertUnauthorized();
});

it('refuses to revoke a refresh token belonging to someone else', function (): void {
    [, $victimRefresh] = signIn();
    [, , $attackerAccess] = signIn();

    withToken($attackerAccess)
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $victimRefresh])
        ->assertNoContent();

    // The attacker's own access token is gone, but the victim's refresh token must be
    // untouched - otherwise logout is a remote session-kill primitive for anyone who can
    // guess or capture another user's refresh token.
    expect(RefreshToken::where('token_hash', hash('sha256', $victimRefresh))->sole()->revoked_at)->toBeNull();
});
