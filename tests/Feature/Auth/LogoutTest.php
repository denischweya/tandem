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

it('does not let the revoked refresh token delete the rest of the user\'s access tokens', function (): void {
    // Two sessions, as a user signed in on two devices has. Logging one out must not be a
    // lever on the other, and the token logout revokes must not become one either.
    $user = userWithPassword();
    [, $refresh, $access] = signIn($user);
    [, $otherRefresh, $otherAccess] = signIn($user);

    withToken($access)->postJson('/api/v1/auth/logout', ['refresh_token' => $refresh])->assertNoContent();

    // Logout deletes the access token the caller authenticated with, and that one only.
    expect($user->tokens()->count())->toBe(1);

    // Now replay the token the logout revoked. It must be refused - and refusing it is ALL
    // that may happen. Mapping Revoked to RevokeChain made this request delete every access
    // token the user held and revoke every refresh token with it: an unauthenticated,
    // unthrottled, repeatable remote sign-out for anyone who ever captured any of this user's
    // refresh tokens, since a revoked row is never deleted.
    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();

    expect($user->tokens()->count())->toBe(1);
    expect(RefreshToken::where('token_hash', hash('sha256', $otherRefresh))->sole()->revoked_at)->toBeNull();

    // The other device is still signed in, on both credentials. forgetAuthenticatedUser()
    // first, because the logout above authenticated and the guard would otherwise answer this
    // from its cached user whether or not the token row still exists - see the helper.
    forgetAuthenticatedUser();
    withToken($otherAccess)->getJson('/api/v1/me')->assertOk();
    postJson('/api/v1/auth/refresh', ['refresh_token' => $otherRefresh])->assertOk();
});

it('refuses the revoked refresh token the same way however many times it is presented', function (): void {
    // Repeatability was half of what made the old behaviour a weapon rather than a bug: the
    // revoked row stays in the table, so the request could be replayed indefinitely to sustain
    // a lockout. Nothing may accumulate across these calls.
    $user = userWithPassword();
    [, $refresh, $access] = signIn($user);
    [, , $otherAccess] = signIn($user);

    withToken($access)->postJson('/api/v1/auth/logout', ['refresh_token' => $refresh])->assertNoContent();

    foreach (range(1, 3) as $ignored) {
        postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
    }

    expect($user->tokens()->count())->toBe(1);
    forgetAuthenticatedUser();
    withToken($otherAccess)->getJson('/api/v1/me')->assertOk();
});
