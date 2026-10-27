<?php

declare(strict_types=1);

use App\Infrastructure\Auth\DummyPasswordHash;
use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\postJson;
use function Pest\Laravel\withToken;

uses(RefreshDatabase::class);

// userWithPassword() moved to tests/Pest.php when RefreshTest/LogoutTest's signIn()
// needed the same user shape: a file-scope helper here is process-global and cannot be
// redeclared, so a second file could only inherit it by load order or duplicate it.

it('returns a token pair and the user', function (): void {
    $user = userWithPassword();

    $response = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct horse battery staple',
    ])->assertOk();

    $response->assertJsonStructure([
        'data' => [
            'access_token',
            'access_token_expires_at',
            'refresh_token',
            'refresh_token_expires_at',
            'user' => ['id', 'email', 'email_verified', 'display_name', 'timezone', 'locale'],
        ],
    ]);

    expect($response->json('data.user.id'))->toBe($user->id);
});

it('issues an access token that expires in fifteen minutes', function (): void {
    $user = userWithPassword();

    $expiresAt = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct horse battery staple',
    ])->json('data.access_token_expires_at');

    expect(CarbonImmutable::parse($expiresAt)->diffInMinutes(now(), true))
        ->toBeGreaterThan(14.0)
        ->toBeLessThan(16.0);
});

it('issues an access token that actually authenticates', function (): void {
    $user = userWithPassword();

    $token = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct horse battery staple',
    ])->json('data.access_token');

    // Through the guard, not by shape. toContain('|') plus a row count was all this asserted,
    // and neither is the claim its name makes: appending one character to the issued token
    // fails 25 tests across five other files and NONE in this one. RefreshTest's equivalent
    // case was upgraded when /me shipped and this one was not.
    expect($token)->toBeString();
    expect(PersonalAccessToken::count())->toBe(1);

    withToken(is_string($token) ? $token : '')
        ->getJson('/api/v1/me')
        ->assertOk()
        ->assertJsonPath('data.id', $user->id);

    // And a token that is one character different does not, so the 200 above is the guard
    // accepting THIS token rather than accepting anything at all.
    forgetAuthenticatedUser();

    withToken((is_string($token) ? $token : '').'x')
        ->getJson('/api/v1/me')
        ->assertUnauthorized();
});

it('stores the refresh token only as a sha256 hash', function (): void {
    $user = userWithPassword();

    $plain = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct horse battery staple',
    ])->json('data.refresh_token');

    $stored = RefreshToken::sole();

    expect($stored->token_hash)->toBe(hash('sha256', $plain));
    expect($stored->token_hash)->not->toBe($plain);
    expect($stored->revoked_at)->toBeNull();
    expect($stored->replaced_by_id)->toBeNull();
});

it('mints a 256-bit refresh token', function (): void {
    $user = userWithPassword();

    $plain = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct horse battery staple',
    ])->json('data.refresh_token');

    // 32 random bytes, hex-encoded.
    expect(strlen((string) $plain))->toBe(64);
    expect($plain)->toMatch('/^[0-9a-f]{64}$/');
});

it('rejects a wrong password with 401 and no token', function (): void {
    $user = userWithPassword();

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong password here'])
        ->assertUnauthorized();

    expect(RefreshToken::count())->toBe(0);
    expect(PersonalAccessToken::count())->toBe(0);
});

it('gives a byte-identical response for an unknown email and a wrong password', function (): void {
    $user = userWithPassword();

    $unknown = postJson('/api/v1/auth/login', ['email' => 'nobody@example.test', 'password' => 'wrong password here']);
    $wrong = postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong password here']);

    // An asymmetry here is an account-existence oracle. This asserts the bodies and the
    // statuses, which is as far as a feature test can reach; the constant-time dummy hash in
    // Login::execute() addresses the timing channel the same reasoning covers.
    expect($unknown->status())->toBe($wrong->status());
    expect($unknown->content())->toBe($wrong->content());
});

it('accepts the email case-insensitively', function (): void {
    $user = userWithPassword();

    postJson('/api/v1/auth/login', [
        'email' => strtoupper($user->email),
        'password' => 'correct horse battery staple',
    ])->assertOk();
});

it('refuses a suspended account', function (): void {
    $user = userWithPassword(attributes: ['suspended_at' => now()]);

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertUnauthorized();

    expect(RefreshToken::count())->toBe(0);
});

it('refuses a soft-deleted account', function (): void {
    $user = userWithPassword();
    $user->delete();

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertUnauthorized();
});

it('signs in an unverified user, because verification gates endpoints and not sign-in', function (): void {
    $user = User::factory()->unverified()->hasProfile()->create(['password_hash' => Hash::make('correct horse battery staple')]);

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertOk()
        ->assertJsonPath('data.user.email_verified', false);
});

it('records a device and binds the refresh token to it when the client sends one', function (): void {
    $user = userWithPassword();

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct horse battery staple',
        'install_id' => 'install-abc',
        'platform' => 'ios',
    ])->assertOk();

    $device = Device::sole();

    expect($device->user_id)->toBe($user->id);
    expect($device->install_id)->toBe('install-abc');
    expect(RefreshToken::sole()->device_id)->toBe($device->id);
});

it('reuses the existing device row for a repeated install_id', function (): void {
    $user = userWithPassword();

    foreach (range(1, 2) as $ignored) {
        postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct horse battery staple',
            'install_id' => 'install-abc',
            'platform' => 'ios',
        ])->assertOk();
    }

    // UNIQUE(user_id, install_id) means a second insert would throw. Signing in twice on one
    // phone is the common case, not an edge case.
    expect(Device::count())->toBe(1);
    expect(RefreshToken::count())->toBe(2);
});

it('leaves device_id null for a client that sends no install_id, as the web BFF does', function (): void {
    $user = userWithPassword();

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertOk();

    // refresh_tokens.device_id is nullable precisely for this case - see the migration
    // comment: the web BFF (ADR 0004) has no install_id concept, and inventing a synthetic
    // one per browser is a decision no spec or ADR has made.
    expect(Device::count())->toBe(0);
    expect(RefreshToken::sole()->device_id)->toBeNull();
});

it('rejects an address email:rfc accepts but the domain value object rejects, with 422 not 500', function (): void {
    // Same gap RegisterRequest closed: 'email:rfc' accepts ada@localhost, filter_var()
    // (what Email::fromString() uses) does not, and LoginController calls Email::fromString()
    // with no try/catch. Without ValidEmail on this request, that address would reach the
    // controller and throw out as a 500 instead of a 422.
    postJson('/api/v1/auth/login', ['email' => 'ada@localhost', 'password' => 'whatever'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('email');
});

it('rejects a non-string email with 422, not a 500 from the rate limiter', function (): void {
    // The limiter used to read $request->input('email') directly inside route middleware,
    // ahead of LoginRequest's own validation - so an array value crashed the (string) cast
    // with an uncaught ErrorException instead of ever reaching the 'string' rule. Moving
    // the throttle into Login::execute(), which only ever runs after LoginRequest has
    // validated, removes that code path entirely; this guards against it coming back.
    postJson('/api/v1/auth/login', ['email' => ['ada@example.test'], 'password' => 'whatever'])
        ->assertStatus(422);
});

it('derives its constant-time dummy hash from the configured bcrypt cost, not a hardcoded one', function (): void {
    // A hardcoded cost-12 literal, under this project's own BCRYPT_ROUNDS=4 test
    // configuration, made the unknown-email path ~222x *slower* than the wrong-password
    // path - the constant-time mitigation inverted, and invisible to any test that only
    // checks statuses and bodies. This checks the property directly instead.
    $info = password_get_info(app(DummyPasswordHash::class)->value());

    expect($info['options']['cost'] ?? null)->toBe((int) config('hashing.bcrypt.rounds'));
});

it('rate limits sign-in to ten failures per fifteen minutes per IP', function (): void {
    $user = userWithPassword();

    // Ten different emails, so the 5-per-email limit cannot be what fires.
    foreach (range(1, 10) as $i) {
        postJson('/api/v1/auth/login', ['email' => "nobody{$i}@example.test", 'password' => 'wrong password here'])
            ->assertUnauthorized();
    }

    postJson('/api/v1/auth/login', ['email' => 'nobody11@example.test', 'password' => 'wrong password here'])
        ->assertStatus(429);
});

it('rate limits sign-in to five failures per fifteen minutes per email, even with the correct password on the sixth try', function (): void {
    $user = userWithPassword();

    foreach (range(1, 5) as $ignored) {
        postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong password here'])
            ->assertUnauthorized();
    }

    // Spec §8.2 (amended) counts failures, not requests: once five failures are on record,
    // the sixth attempt is refused before the password is even checked, so a *correct*
    // password here must still 429 rather than succeed. This is what proves the throttle
    // fires ahead of the controller's own credential check, and the per-email limit must
    // fire at six even though the per-IP limit here allows ten.
    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertStatus(429);
});

it('does not count a successful sign-in against either rate limit', function (): void {
    $user = userWithPassword();

    // Six successes, one email, one IP: under request-counting this would have tripped the
    // 5-per-email limit on the sixth call. Under failure-counting it must not, because
    // Login::execute() clears both keys on every success - this is the whole point of the
    // spec amendment, and the converse of the test above.
    foreach (range(1, 6) as $ignored) {
        postJson('/api/v1/auth/login', [
            'email' => $user->email,
            'password' => 'correct horse battery staple',
        ])->assertOk();
    }
});

it('forgives prior failures on a success, so a later run of failures gets a fresh budget', function (): void {
    $user = userWithPassword();

    // Four failures - under the five-per-email cap, so no 429 yet.
    foreach (range(1, 4) as $ignored) {
        postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong password here'])
            ->assertUnauthorized();
    }

    // A success in between must clear the count those four failures left behind. Without
    // that, this test's second batch of four failures would be the fifth through eighth
    // against the same uncleared counter, and the cap would fire inside it.
    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertOk();

    foreach (range(1, 4) as $ignored) {
        postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong password here'])
            ->assertUnauthorized();
    }
});
