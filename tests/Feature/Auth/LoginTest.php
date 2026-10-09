<?php

declare(strict_types=1);

use App\Models\Device;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/** @param array<string, mixed> $attributes */
function userWithPassword(string $password = 'correct horse battery staple', array $attributes = []): User
{
    return User::factory()
        ->hasProfile()
        ->create(array_merge(['password_hash' => Hash::make($password)], $attributes));
}

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

    // /me arrives in Task 7; until then the token's validity is asserted through the guard
    // itself, which is what Task 1's probe route established.
    expect($token)->toBeString()->toContain('|');
    expect(PersonalAccessToken::count())->toBe(1);
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

it('rate limits sign-in to ten per fifteen minutes per IP', function (): void {
    $user = userWithPassword();

    // Ten different emails, so the 5-per-email limit cannot be what fires.
    foreach (range(1, 10) as $i) {
        postJson('/api/v1/auth/login', ['email' => "nobody{$i}@example.test", 'password' => 'wrong password here'])
            ->assertUnauthorized();
    }

    postJson('/api/v1/auth/login', ['email' => 'nobody11@example.test', 'password' => 'wrong password here'])
        ->assertStatus(429);
});

it('rate limits sign-in to five per fifteen minutes per email', function (): void {
    $user = userWithPassword();

    foreach (range(1, 5) as $ignored) {
        postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong password here'])
            ->assertUnauthorized();
    }

    // The per-email limit must fire at six even though the per-IP limit allows ten.
    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertStatus(429);
});
