<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\Mail\ResetPasswordMail;
use App\Infrastructure\Outbox\OutboxDispatcher;
use App\Models\OutboxEvent;
use App\Models\PasswordResetToken;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    RateLimiter::clear('auth-forgot-password');
    RateLimiter::clear('auth-login');
});

/** Requests a reset and returns the plaintext token the email carries. */
function requestReset(User $user): string
{
    postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();

    app(OutboxDispatcher::class)->dispatchPending();

    $captured = null;
    Mail::assertSent(ResetPasswordMail::class, function (ResetPasswordMail $mail) use (&$captured): bool {
        $captured = $mail->token;

        return true;
    });

    return (string) $captured;
}

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

    $known = postJson('/api/v1/auth/forgot-password', ['email' => $user->email]);
    RateLimiter::clear('auth-forgot-password');
    $unknown = postJson('/api/v1/auth/forgot-password', ['email' => 'nobody@example.test']);

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
