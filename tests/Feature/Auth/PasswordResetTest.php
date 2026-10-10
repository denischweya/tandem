<?php

declare(strict_types=1);

use App\Infrastructure\Outbox\OutboxDispatcher;
use App\Models\OutboxEvent;
use App\Models\PasswordResetToken;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

// No RateLimiter::clear() calls here for either 'auth-forgot-password' or 'auth-login' - the
// brief had both, and both are no-ops dressed as protection, for the same reason RefreshTest.php
// already documents for 'auth-login': the named-limiter middleware hashes its real key as
// md5($limiterName.$limit->key) (Illuminate\Routing\Middleware\ThrottleRequests::
// handleRequestUsingNamedLimiter()), and LoginRateLimiter writes 'auth-login:ip:<ip>' /
// 'auth-login:email:<email>' directly - neither real key is the bare string this file used to
// clear. Nothing is needed in its place: CACHE_STORE is 'array' under phpunit.xml and the
// application is rebuilt per test, so limiter state never crosses a test boundary.
beforeEach(function (): void {
    Mail::fake();
});

it('accepts a reset request for a known address and queues one email', function (): void {
    $user = User::factory()->hasProfile()->create();

    postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();

    expect(OutboxEvent::where('event_type', 'password.reset_requested')->count())->toBe(1);
    expect(PasswordResetToken::count())->toBe(1);
});

it('accepts a reset request for an unknown address and sends nothing', function (): void {
    postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test'])
        ->assertAccepted();

    // Same status, same body, no token, no mail. A 404 here would be a free account-existence
    // oracle that needs no credentials at all.
    expect(PasswordResetToken::count())->toBe(0);
    expect(OutboxEvent::count())->toBe(0);
});

it('returns a byte-identical response for a known and an unknown address', function (): void {
    $user = User::factory()->hasProfile()->create();

    // Two different emails, so this is not the same rate-limit bucket and needs no clearing
    // between the calls - see the no-op comment on RateLimiter::clear() above.
    $known = postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
    $unknown = postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

    // Pins the actual value, not just the equality: toBe() alone passes identically whether
    // both responses are 202 or both are 429/404/500, so a regression that broke the oracle
    // protection for every caller could still leave this assertion green.
    $known->assertAccepted();
    $unknown->assertAccepted();

    expect($known->status())->toBe($unknown->status());
    expect($known->content())->toBe($unknown->content());
});

it('stores the reset token only as a sha256 hash', function (): void {
    $user = User::factory()->hasProfile()->create();
    $plain = requestReset($user);

    $stored = PasswordResetToken::sole();

    expect($stored->token_hash)->toBe(hash('sha256', $plain));
    expect($stored->used_at)->toBeNull();
});

it('stores the outbox token encrypted, not as the plaintext the mail carries', function (): void {
    // Guards against the encryption being "simplified" away later. OutboxDispatcher sets
    // published_at and never deletes the row, so a plaintext token here would persist
    // indefinitely in outbox_events.payload - exactly what §142.7 forbids.
    $user = User::factory()->hasProfile()->create();
    $plain = requestReset($user);

    $event = OutboxEvent::where('event_type', 'password.reset_requested')->sole();

    expect($event->payload['token'])->not->toBe($plain);
    expect(Crypt::decryptString($event->payload['token']))->toBe($plain);
});

it('resets the password and lets the user sign in with the new one', function (): void {
    $user = User::factory()->hasProfile()->create(['password_hash' => Hash::make('the old password here')]);
    $plain = requestReset($user);

    postJson('/api/v1/auth/reset-password', [
        'token' => $plain,
        'email' => $user->email,
        'password' => 'a brand new password',
    ])->assertNoContent();

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'a brand new password'])
        ->assertOk();

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'the old password here'])
        ->assertUnauthorized();
});

it('refuses to use a reset token twice', function (): void {
    $user = User::factory()->hasProfile()->create();
    $plain = requestReset($user);

    $payload = ['token' => $plain, 'email' => $user->email, 'password' => 'a brand new password'];

    postJson('/api/v1/auth/reset-password', $payload)->assertNoContent();
    postJson('/api/v1/auth/reset-password', $payload)->assertStatus(422);
});

it('refuses an expired reset token', function (): void {
    $user = User::factory()->hasProfile()->create();
    $plain = requestReset($user);

    PasswordResetToken::query()->update(['expires_at' => now()->subMinute()]);

    postJson('/api/v1/auth/reset-password', [
        'token' => $plain,
        'email' => $user->email,
        'password' => 'a brand new password',
    ])->assertStatus(422);
});

it('refuses a reset token presented with a different email', function (): void {
    $victim = User::factory()->hasProfile()->create();
    $other = User::factory()->hasProfile()->create();
    $plain = requestReset($victim);
    $otherHashBeforeAttempt = $other->password_hash;

    // The token alone must not be enough to change an arbitrary account's password.
    postJson('/api/v1/auth/reset-password', [
        'token' => $plain,
        'email' => $other->email,
        'password' => 'a brand new password',
    ])->assertStatus(422);

    // refresh(), not fresh(): fresh() returns a nullable copy, and comparing against a local
    // variable captured before the request (rather than against $other->password_hash read
    // after an in-place refresh, which would make both sides of the comparison the same
    // already-refreshed value) is what keeps this a real check that the hash never changed.
    expect($other->refresh()->password_hash)->toBe($otherHashBeforeAttempt);
});

it('revokes every existing session when the password changes', function (): void {
    // signIn() expects this password, so the user is seeded with it rather than patched after.
    $user = User::factory()->hasProfile()->create(['password_hash' => Hash::make('correct horse battery staple')]);

    [, $refresh] = signIn($user);

    $plain = requestReset($user);

    postJson('/api/v1/auth/reset-password', [
        'token' => $plain,
        'email' => $user->email,
        'password' => 'a brand new password',
    ])->assertNoContent();

    // A password reset is how someone recovers an account they believe is compromised. Leaving
    // the attacker's refresh token live would make the reset cosmetic.
    expect(RefreshToken::whereNull('revoked_at')->count())->toBe(0);
    expect($user->refresh()->tokens()->count())->toBe(0);
    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
});

it('enforces the password rule on the new password', function (): void {
    $user = User::factory()->hasProfile()->create();
    $plain = requestReset($user);

    postJson('/api/v1/auth/reset-password', [
        'token' => $plain,
        'email' => $user->email,
        'password' => 'short',
    ])->assertStatus(422)->assertJsonValidationErrorFor('password');
});

it('rate limits reset requests to three per hour per email', function (): void {
    $user = User::factory()->hasProfile()->create();

    foreach (range(1, 3) as $ignored) {
        postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();
    }

    postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(429);
});

it('rejects a forgot-password address email:rfc accepts but the domain value object rejects, with 422 not 500', function (): void {
    // Same gap RegisterRequest/LoginRequest closed: 'email:rfc' accepts ada@localhost,
    // filter_var() (what Email::fromString() uses) does not, and ForgotPasswordController
    // calls Email::fromString() with no try/catch. Without ValidEmail on this request, that
    // address would reach the controller and throw out as a 500 instead of a 422.
    postJson('/api/v1/auth/forgot-password', ['email' => 'ada@localhost'])
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('email');
});

it('rejects a non-string forgot-password email with 422, not a 500 from the rate limiter', function (): void {
    // auth.forgot-password is the one route in this codebase with a route-level throttle:
    // middleware, so the limiter closure in AppServiceProvider runs on every request to this
    // URL before ForgotPasswordRequest ever validates - unlike auth-login, which has no
    // throttle: middleware at all. An array value for `email` passes route dispatch, and a
    // bare (string) cast on it inside that closure would crash with an uncaught
    // ErrorException - the same reachable 500 Task 4 found and fixed for the login limiter.
    // This request necessarily goes through that closure: it is what proves the closure's
    // own is_string() guard, not just ForgotPasswordRequest's 'string' rule, holds here.
    postJson('/api/v1/auth/forgot-password', ['email' => ['ada@example.test']])
        ->assertStatus(422);
});

it('rejects a reset-password address email:rfc accepts but the domain value object rejects, with 422 not 500', function (): void {
    // Same gap as forgot-password above: ResetPasswordController also calls
    // Email::fromString() with no try/catch.
    postJson('/api/v1/auth/reset-password', [
        'token' => str_repeat('a', 64),
        'email' => 'ada@localhost',
        'password' => 'a brand new password',
    ])->assertStatus(422)->assertJsonValidationErrorFor('email');
});

it('keys the forgot-password limit on the email and not on the IP', function (): void {
    // §8.2's row is 3 per hour per EMAIL. Re-keying AppServiceProvider's limiter closure to
    // ->by('ip:'.$request->ip()) left all 188 tests green, because the only existing test here
    // sends one address from one address: with one email and one IP the two keys are
    // indistinguishable. This is LoginTest's discriminating shape - "ten different emails, so
    // the 5-per-email limit cannot be what fires" - run the other way round.
    $first = User::factory()->hasProfile()->create(['email' => 'first@example.test']);
    $second = User::factory()->hasProfile()->create(['email' => 'second@example.test']);

    foreach (range(1, 3) as $ignored) {
        postJson('/api/v1/auth/forgot-password', ['email' => $first->email])->assertAccepted();
    }

    postJson('/api/v1/auth/forgot-password', ['email' => $first->email])->assertStatus(429);

    // Same IP, different address, inside the same window: the second account's own budget is
    // untouched. Under IP keying this is a 429, and one person asking for a reset link three
    // times locks out everyone behind their NAT.
    postJson('/api/v1/auth/forgot-password', ['email' => $second->email])->assertAccepted();
});

it('does not let a different casing or stray whitespace buy a fresh forgot-password budget', function (): void {
    // The limiter key goes through EmailInput::normalize(), the same call
    // ForgotPasswordRequest's NormalizesEmailInput trait makes - which is the only reason the
    // 3-per-hour-per-email limit is un-bypassable. Dropping the normalize() and keying on the
    // raw input also left all 188 tests green, because no test had ever sent the same address
    // twice in two different spellings.
    $user = User::factory()->hasProfile()->create(['email' => 'ada@example.test']);

    foreach (range(1, 3) as $ignored) {
        postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();
    }

    postJson('/api/v1/auth/forgot-password', ['email' => 'ADA@Example.TEST'])->assertStatus(429);
    postJson('/api/v1/auth/forgot-password', ['email' => '  ada@example.test  '])->assertStatus(429);
});
