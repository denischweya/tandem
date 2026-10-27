<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use Illuminate\Foundation\Testing\RefreshDatabase;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

// signIn() and userWithPassword() live in tests/Pest.php, not at the top of this file:
// LogoutTest.php needs signIn() too, and a file-scope helper here would make that file
// depend on this one's load order.
//
// No beforeEach(RateLimiter::clear('auth-login')) either. The brief had one, but
// LoginRateLimiter writes 'auth-login:ip:<ip>' and 'auth-login:email:<email>' - clearing
// the bare string 'auth-login' touches a key the limiter never writes, which is a no-op
// dressed as protection. Nothing is needed in its place: CACHE_STORE is 'array' under
// phpunit.xml and the application is rebuilt per test, so limiter state never crosses a
// test boundary, and signIn() succeeds, which clears both real keys anyway.

it('exchanges a live refresh token for a new pair', function (): void {
    [$user, $refresh] = signIn();

    $new = postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])
        ->assertOk()
        ->assertJsonStructure(['data' => ['access_token', 'access_token_expires_at', 'refresh_token', 'refresh_token_expires_at', 'user']])
        ->json('data');

    expect($new['refresh_token'])->not->toBe($refresh);
    expect($new['user']['id'])->toBe($user->id);
});

it('marks the presented token rotated and links it to its successor', function (): void {
    [, $refresh] = signIn();

    $newRefresh = postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->json('data.refresh_token');

    $old = RefreshToken::where('token_hash', hash('sha256', $refresh))->sole();
    $new = RefreshToken::where('token_hash', hash('sha256', $newRefresh))->sole();

    expect($old->revoked_at)->not->toBeNull();
    expect($old->replaced_by_id)->toBe($new->id);
    expect($new->revoked_at)->toBeNull();
});

it('rejects the same refresh token used twice', function (): void {
    [, $refresh] = signIn();

    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertOk();
    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
});

it('revokes the entire chain when a rotated token is replayed', function (): void {
    [, $tokenA] = signIn();

    $tokenB = postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenA])->json('data.refresh_token');
    $tokenC = postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenB])->json('data.refresh_token');

    // C is live and legitimate at this point. Replaying A is the stolen-token signal.
    postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenA])->assertUnauthorized();

    foreach ([$tokenA, $tokenB, $tokenC] as $token) {
        expect(RefreshToken::where('token_hash', hash('sha256', $token))->sole()->revoked_at)
            ->not->toBeNull();
    }

    // And the legitimate client is now logged out too, which is the intended cost: the
    // alternative is leaving a thief with a working session.
    postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenC])->assertUnauthorized();
});

it('deletes every access token the user holds when a replay is detected', function (): void {
    [$user, $tokenA] = signIn();

    $tokenB = postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenA])->json('data.refresh_token');
    $live = postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenB])->assertOk()->json('data');

    // One access token per login and per refresh, and the newest is live and legitimate.
    expect($user->tokens()->count())->toBe(3);

    postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenA])->assertUnauthorized();

    // Spec §9.1.1. Revoking the lineage alone leaves every access token already minted from it
    // working until it expires - up to TokenIssuer::TTL_MINUTES of full API access for whoever
    // triggered the detection. Nothing else in this file would notice: the chain test checks
    // refresh_tokens rows, and refresh needs no access token to be refused.
    expect($user->tokens()->count())->toBe(0);

    // The row count is not the claim that matters - the guard refusing the token is. 401 here
    // is Sanctum rejecting a token whose row is gone, before LogoutController is reached.
    withToken($live['access_token'])
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $live['refresh_token']])
        ->assertUnauthorized();
});

it('still detects a replayed ancestor after the live tail was revoked by a logout', function (): void {
    // The claim that makes Revoked => Reject safe, asserted rather than trusted:
    // RefreshTokenRotator::stateOf() tests replaced_by_id BEFORE revoked_at, so an ancestor of
    // a chain whose tail was revoked without being rotated still reports Rotated - and a
    // replay of it is still the stolen-token signal with its full consequences.
    $user = userWithPassword();
    [, $tokenA, $accessA] = signIn($user);

    $tokenB = postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenA])->assertOk()->json('data.refresh_token');

    // B is the live tail. Logging out revokes it WITHOUT rotating it, so B is Revoked while A
    // is both revoked and replaced - the exact row shape this test exists for.
    withToken($accessA)->postJson('/api/v1/auth/logout', ['refresh_token' => $tokenB])->assertNoContent();

    $b = RefreshToken::where('token_hash', hash('sha256', $tokenB))->sole();
    expect($b->revoked_at)->not->toBeNull();
    expect($b->replaced_by_id)->toBeNull();

    // A second, unrelated session, so the consequences of a detected replay are observable.
    [, $otherRefresh, $otherAccess] = signIn($user);

    postJson('/api/v1/auth/refresh', ['refresh_token' => $tokenA])->assertUnauthorized();

    // Detected, not merely refused: every access token the user holds is gone (spec §9.1.1),
    // so the other session's access token stops authenticating too.
    expect($user->tokens()->count())->toBe(0);
    // forgetAuthenticatedUser() first, or this assertion cannot fail: the logout above
    // authenticated, and the guard caches its resolved user for the rest of the test. See the
    // helper's docblock for the measurement.
    forgetAuthenticatedUser();
    withToken($otherAccess)->getJson('/api/v1/me')->assertUnauthorized();

    // The other session's REFRESH token survives, and that is correct rather than a gap:
    // revokeChainFrom() walks one lineage, and §9.1 makes refresh-token revocation per device.
    // A second login starts its own chain, which this replay says nothing about. Measured here
    // because the access-token scope and the refresh-token scope genuinely differ, and reading
    // §9.1.1's "every access token the user holds" as "every credential" would be wrong.
    expect(RefreshToken::where('token_hash', hash('sha256', $otherRefresh))->sole()->revoked_at)->toBeNull();
    postJson('/api/v1/auth/refresh', ['refresh_token' => $otherRefresh])->assertOk();
});

it('rejects an expired refresh token without revoking the chain', function (): void {
    [, $refresh] = signIn();

    RefreshToken::where('token_hash', hash('sha256', $refresh))
        ->update(['expires_at' => now()->subMinute()]);

    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();

    expect(RefreshToken::where('token_hash', hash('sha256', $refresh))->sole()->revoked_at)->toBeNull();
});

it('rejects an unknown refresh token', function (): void {
    postJson('/api/v1/auth/refresh', ['refresh_token' => bin2hex(random_bytes(32))])
        ->assertUnauthorized();
});

it('gives a byte-identical 401 for an unknown, expired, revoked and replayed token', function (): void {
    // The one-message rule, asserted rather than assumed. Four different causes, one body:
    // reporting which it was tells an attacker holding a captured token whether their replay
    // was detected, and tells a legitimate client nothing it can act on - every case means
    // "sign in again". Nothing else in this suite would notice the four diverging, because
    // every other test here checks only the status.
    $unknown = postJson('/api/v1/auth/refresh', ['refresh_token' => bin2hex(random_bytes(32))]);

    [, $expiredToken] = signIn();
    RefreshToken::where('token_hash', hash('sha256', $expiredToken))->update(['expires_at' => now()->subMinute()]);
    $expired = postJson('/api/v1/auth/refresh', ['refresh_token' => $expiredToken]);

    [$revokedUser, $revokedToken] = signIn();
    RefreshToken::where('token_hash', hash('sha256', $revokedToken))->update(['revoked_at' => now()]);
    $accessTokensBeforeTheRevokedReplay = $revokedUser->tokens()->count();
    $revoked = postJson('/api/v1/auth/refresh', ['refresh_token' => $revokedToken]);

    // The revoked case is the one that must do nothing beyond answering 401. It used to map to
    // RevokeChain alongside Rotated, so this very request deleted every access token the user
    // held - see LogoutTest's two cases for the full account. Asserted here because this test
    // already walks all four causes and would otherwise keep passing on the body alone.
    expect($revokedUser->tokens()->count())->toBe($accessTokensBeforeTheRevokedReplay);

    [, $replayedToken] = signIn();
    postJson('/api/v1/auth/refresh', ['refresh_token' => $replayedToken])->assertOk();
    $replayed = postJson('/api/v1/auth/refresh', ['refresh_token' => $replayedToken]);

    expect($unknown->status())->toBe(401);

    foreach ([$expired, $revoked, $replayed] as $response) {
        expect($response->status())->toBe(401);
        expect($response->content())->toBe($unknown->content());
    }
});
it('issues an access token that authenticates after a refresh', function (): void {
    [, $refresh] = signIn();

    $before = PersonalAccessToken::count();

    $new = postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertOk()->json('data');

    expect(PersonalAccessToken::count())->toBe($before + 1);

    // Counting rows alone would leave this test's name unearned - a row exists whether or
    // not the token it describes is accepted by the guard. POST /auth/logout is the only
    // auth:sanctum route this API has, so it is what actually exercises the new token.
    withToken($new['access_token'])
        ->postJson('/api/v1/auth/logout', ['refresh_token' => $new['refresh_token']])
        ->assertNoContent();
});

it('keeps the device binding across a rotation', function (): void {
    $user = userWithPassword();

    $refresh = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct horse battery staple',
        'install_id' => 'install-abc',
        'platform' => 'android',
    ])->json('data.refresh_token');

    $newRefresh = postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->json('data.refresh_token');

    // ADR 0003 makes revocation per device, which is only meaningful if the successor stays
    // bound to the same device as its predecessor.
    expect(RefreshToken::where('token_hash', hash('sha256', $newRefresh))->sole()->device_id)
        ->toBe(Device::sole()->id);
});

it('refuses to refresh a soft-deleted user', function (): void {
    [$user, $refresh] = signIn();

    $user->delete();

    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
});
