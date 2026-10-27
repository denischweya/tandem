<?php

declare(strict_types=1);

use App\Domain\Shared\Clock;
use App\Domain\Shared\FrozenClock;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

it('verifies a user presenting a valid signed payload', function (): void {
    $user = User::factory()->unverified()->hasProfile()->create();

    postJson('/api/v1/auth/verify-email?'.signedVerificationQuery($user))
        ->assertOk()
        ->assertJsonPath('data.email_verified', true);

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

it('is idempotent, and does not re-stamp an already verified address', function (): void {
    // Two things this test could not see before. Its user came from the plain factory, which
    // is already verified, so the FIRST call never exercised the unverified -> verified
    // transition; and ->not->toBeNull() cannot tell a preserved timestamp from a fresh one, so
    // deleting VerifyEmail::execute()'s early return changed nothing observable.
    //
    // A frozen clock, moved between the two calls, is what makes the re-stamp visible: the
    // second call runs a day later, and email_verified_at must still read the first instant.
    //
    // Frozen at the real instant rather than a fixed date, because EmailVerificationLink also
    // reads this Clock for the link's 24-hour expiry, while signatureHasNotExpired() compares
    // against Carbon's real now(). A clock set to a fixed past date therefore mints a link
    // that is already expired and the first request 403s - measured, which is why this is a
    // relative freeze.
    $first = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($first));

    $user = User::factory()->unverified()->hasProfile()->create();
    $query = signedVerificationQuery($user);

    postJson('/api/v1/auth/verify-email?'.$query)->assertOk()->assertJsonPath('data.email_verified', true);

    expect($user->refresh()->email_verified_at?->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($first->format('Y-m-d H:i:s'));

    app()->instance(Clock::class, new FrozenClock($first->modify('+1 day')));

    postJson('/api/v1/auth/verify-email?'.$query)->assertOk()->assertJsonPath('data.email_verified', true);

    expect($user->refresh()->email_verified_at?->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($first->format('Y-m-d H:i:s'));
});

it('rejects a tampered signature', function (): void {
    $user = User::factory()->unverified()->hasProfile()->create();
    $query = signedVerificationQuery($user);

    postJson('/api/v1/auth/verify-email?'.$query.'x')->assertForbidden();

    expect($user->refresh()->email_verified_at)->toBeNull();
});

it('rejects a link whose hash does not match the current email', function (): void {
    $user = User::factory()->unverified()->hasProfile()->create();
    $query = signedVerificationQuery($user);

    // The email changed after the link was sent. The signature is still valid - it is the
    // sha1(email) hash inside it that must stop this, which is the whole reason the hash is
    // in the payload rather than just the id.
    $user->forceFill(['email' => 'moved@example.test'])->save();

    postJson('/api/v1/auth/verify-email?'.$query)->assertForbidden();

    expect($user->refresh()->email_verified_at)->toBeNull();
});

it('rejects an expired link', function (): void {
    $user = User::factory()->unverified()->hasProfile()->create();

    $expired = URL::temporarySignedRoute(
        'auth.verify-email',
        now()->subMinute(),
        ['id' => $user->id, 'hash' => sha1($user->email)],
    );

    postJson('/api/v1/auth/verify-email?'.parse_url($expired, PHP_URL_QUERY))
        ->assertForbidden();
});

it('returns 403 and not 404 for an unknown user id, leaking nothing about which ids exist', function (): void {
    $unknown = (string) Str::uuid7();

    $signed = URL::temporarySignedRoute(
        'auth.verify-email',
        now()->addHour(),
        ['id' => $unknown, 'hash' => sha1('whoever@example.test')],
    );

    // A signed link for a nonexistent user is indistinguishable from one whose hash does not
    // match: both are "this link is not valid". Returning 404 here would make the endpoint an
    // account-existence oracle for anyone able to mint a link for an id of their choosing -
    // which means anyone holding the APP_KEY, and NOT, as this comment used to say, anyone
    // holding any valid link: the signature covers the id, so substituting another id
    // invalidates it. See VerifyEmail::forSignedPayload() for the measurement.
    postJson('/api/v1/auth/verify-email?'.parse_url($signed, PHP_URL_QUERY))
        ->assertForbidden();
});
