<?php

declare(strict_types=1);

use App\Infrastructure\Auth\EmailVerificationLink;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

/** Returns the query string the web app relays back to this API. */
function signedVerificationQuery(User $user): string
{
    $link = app(EmailVerificationLink::class)->for($user);

    return (string) parse_url($link, PHP_URL_QUERY);
}

it('verifies a user presenting a valid signed payload', function (): void {
    $user = User::factory()->unverified()->hasProfile()->create();

    postJson('/api/v1/auth/verify-email?'.signedVerificationQuery($user))
        ->assertOk()
        ->assertJsonPath('data.email_verified', true);

    expect($user->refresh()->email_verified_at)->not->toBeNull();
});

it('is idempotent for an already verified user', function (): void {
    $user = User::factory()->hasProfile()->create();
    $query = signedVerificationQuery($user);

    postJson('/api/v1/auth/verify-email?'.$query)->assertOk();
    postJson('/api/v1/auth/verify-email?'.$query)->assertOk();

    expect($user->refresh()->email_verified_at)->not->toBeNull();
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
    // match: both are "this link is not valid". Returning 404 here would turn the endpoint
    // into an account-existence oracle for anyone who can mint a link - which, since the
    // signature covers the id, means anyone holding any valid link.
    postJson('/api/v1/auth/verify-email?'.parse_url($signed, PHP_URL_QUERY))
        ->assertForbidden();
});
