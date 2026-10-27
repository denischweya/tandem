<?php

declare(strict_types=1);

use App\Domain\Shared\Clock;
use App\Domain\Shared\FrozenClock;
use App\Infrastructure\Auth\PasswordResetTokens;
use App\Infrastructure\Auth\RefreshTokenIssuer;
use App\Infrastructure\Auth\TokenIssuer;
use App\Models\PasswordResetToken;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;

use function Pest\Laravel\postJson;
use function Pest\Laravel\travel;

uses(RefreshDatabase::class);

/**
 * Every time constant that bounds a credential, and every window that bounds an attempt rate.
 *
 * Each of the five mutations below left the whole suite green before this file existed:
 *
 *   - delete createToken()'s third argument, and access tokens never expire while the login
 *     response goes on promising fifteen minutes. config('sanctum.expiration') is null, so the
 *     per-token expires_at is the ONLY bound there is - this is spec §17 criterion 11's whole
 *     mechanism ("refresh on 401"), and without it the 401 never comes;
 *   - RefreshTokenIssuer::TTL_DAYS 30 -> 3650;
 *   - PasswordResetTokens::TTL_MINUTES 60 -> 60000;
 *   - LoginRateLimiter::DECAY_SECONDS 900 -> 1;
 *   - Limit::perHour(5) -> perMinute(5) on auth-register.
 *
 * Two kinds of assertion, because one kind cannot catch both failures. The NUMBER is asserted
 * against spec §9.1 / §8.2 directly - those are a Global Constraint, so pinning the literal is
 * pinning the spec, not keeping a second copy of it. The MECHANISM is asserted separately, and
 * derived from the constant, so that a constant changed on purpose moves one assertion rather
 * than silently disabling the behaviour.
 *
 * The windows are testable because phpunit.xml pins CACHE_STORE=array, and ArrayStore computes
 * and compares expiries through Carbon::now() - so travel() moves them. Measured: without that,
 * none of the three rate-window cases below can fail.
 */

/**
 * Moves what Clock::now() answers, which travel() cannot reach.
 *
 * SystemClock builds `new DateTimeImmutable('now')` because app/Domain imports nothing from
 * Illuminate (spec §6), so it never consults Carbon::setTestNow - the thing travel() moves.
 * Every expiry decided in the domain or application layer (refresh-token state, password-reset
 * state) therefore needs the binding replaced rather than the clock travelled.
 */
function moveClockForward(int $amount, string $unit): void
{
    app()->instance(Clock::class, new FrozenClock(
        (new DateTimeImmutable('now', new DateTimeZone('UTC')))->modify("+{$amount} {$unit}"),
    ));
}

it('expires an access token fifteen minutes after it is issued, per spec 9.1', function (): void {
    expect(TokenIssuer::TTL_MINUTES)->toBe(15);

    // A frozen clock, so "fifteen minutes from issue" is exact rather than approximate: the
    // issuer computes the expiry from Clock::now(), and the assertion computes it from the
    // same instant.
    $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($instant));

    $user = userWithPassword();

    $expiresAt = postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct horse battery staple',
    ])->assertOk()->json('data.access_token_expires_at');

    $expected = $instant->modify('+'.TokenIssuer::TTL_MINUTES.' minutes');

    // The response's promise...
    expect($expiresAt)->toBe($expected->format(DATE_RFC3339_EXTENDED));

    // ...and the only thing that enforces it. config('sanctum.expiration') is null, and
    // Sanctum's Guard checks `! $accessToken->expires_at || ! $accessToken->expires_at
    // ->isPast()` - so a NULL here is a token that never expires, with the response still
    // promising fifteen minutes. Dropping createToken()'s third argument produces exactly
    // that, and nothing else in the suite looks at this column.
    $stored = PersonalAccessToken::sole();

    expect($stored->expires_at)->not->toBeNull();
    // Compared at second precision, not DATE_RFC3339_EXTENDED: Eloquent serialises a datetime
    // with $model->getDateFormat(), which is 'Y-m-d H:i:s', so the microseconds the clock
    // produced are truncated on the way into the column whatever the column's own precision
    // is. Measured - the response carries .233 and the row carries .000 for the same instant.
    expect($stored->expires_at?->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($expected->format('Y-m-d H:i:s'));
});

it('stops accepting an access token once its persisted expiry has passed', function (): void {
    // The column is only a bound if the guard reads it. Asserted through the guard rather than
    // by comparing timestamps, because that is the half that matters to a client: criterion
    // 11's "refresh on 401" needs the 401 to actually arrive.
    [, , $access] = signIn();

    travel(TokenIssuer::TTL_MINUTES + 1)->minutes();
    forgetAuthenticatedUser();

    postJson('/api/v1/auth/logout', ['refresh_token' => 'x'])->assertUnauthorized();
    \Pest\Laravel\withToken($access)->getJson('/api/v1/me')->assertUnauthorized();
});

it('expires a refresh token thirty days after it is issued, per spec 9.1', function (): void {
    expect(RefreshTokenIssuer::TTL_DAYS)->toBe(30);

    $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($instant));

    $user = userWithPassword();

    postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'correct horse battery staple',
    ])->assertOk();

    // Second precision: see the access-token case above for why the column cannot carry the
    // clock's microseconds.
    expect(RefreshToken::sole()->expires_at->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($instant->modify('+'.RefreshTokenIssuer::TTL_DAYS.' days')->format('Y-m-d H:i:s'));
});

it('still accepts a refresh token one day before the thirty are up', function (): void {
    [, $refresh] = signIn();

    // A rebound FrozenClock, NOT travel(): RefreshTokenRotator::stateOf() compares expires_at
    // against Clock::now(), and SystemClock builds `new DateTimeImmutable('now')` rather than
    // going through Carbon - deliberately, since app/Domain may import nothing from Illuminate
    // (spec §6). So Carbon::setTestNow, which is what travel() moves, has no effect on
    // anything decided through Clock. Measured: travelling 31 days forward and refreshing
    // returns 200. travel() is still the right tool for the rate-limit windows below, which
    // are decided by the cache store and do read Carbon.
    moveClockForward(RefreshTokenIssuer::TTL_DAYS - 1, 'days');

    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertOk();
});

it('refuses a refresh token presented after the thirty days are up', function (): void {
    [, $refresh] = signIn();

    moveClockForward(RefreshTokenIssuer::TTL_DAYS + 1, 'days');

    postJson('/api/v1/auth/refresh', ['refresh_token' => $refresh])->assertUnauthorized();
});

it('expires a password reset token sixty minutes after it is issued', function (): void {
    Mail::fake();

    expect(PasswordResetTokens::TTL_MINUTES)->toBe(60);

    $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($instant));

    $user = User::factory()->hasProfile()->create();
    requestReset($user);

    expect(PasswordResetToken::sole()->expires_at->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($instant->modify('+'.PasswordResetTokens::TTL_MINUTES.' minutes')->format('Y-m-d H:i:s'));
});

it('refuses a password reset token presented after the sixty minutes are up', function (): void {
    Mail::fake();

    $user = User::factory()->hasProfile()->create(['password_hash' => Hash::make('the old password here')]);
    $plain = requestReset($user);

    // The Clock, not travel() - see the refresh-token case above.
    moveClockForward(PasswordResetTokens::TTL_MINUTES + 1, 'minutes');

    postJson('/api/v1/auth/reset-password', [
        'token' => $plain,
        'email' => $user->email,
        'password' => 'a brand new password',
    ])->assertStatus(422);

    // The old password still works, which is what "refused" has to mean here.
    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'the old password here'])
        ->assertOk();
});

it('holds the login throttle for the whole fifteen minutes spec 8.2 specifies', function (): void {
    $user = userWithPassword();

    foreach (range(1, 5) as $ignored) {
        postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong password here'])
            ->assertUnauthorized();
    }

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertStatus(429);

    // Fourteen minutes in, still locked. DECAY_SECONDS at 1 - or at anything short of 15
    // minutes - passes every other test in this suite, because nothing else lets the clock
    // move at all.
    travel(14)->minutes();

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertStatus(429);
});

it('releases the login throttle once the fifteen minutes are up', function (): void {
    $user = userWithPassword();

    foreach (range(1, 5) as $ignored) {
        postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'wrong password here'])
            ->assertUnauthorized();
    }

    // The converse, so a DECAY_SECONDS raised instead of lowered - a window that never
    // releases - fails too. §8.2's number is 15 minutes, not "at least 15 minutes".
    travel(16)->minutes();

    postJson('/api/v1/auth/login', ['email' => $user->email, 'password' => 'correct horse battery staple'])
        ->assertOk();
});

it('holds the registration throttle for the whole hour spec 8.2 specifies', function (): void {
    Mail::fake();

    foreach (range(1, 5) as $i) {
        postJson('/api/v1/auth/register', validRegistration(['email' => "ada{$i}@example.test"]))
            ->assertCreated();
    }

    postJson('/api/v1/auth/register', validRegistration(['email' => 'ada6@example.test']))
        ->assertStatus(429);

    // Limit::perMinute(5) would have released by now, and passes the existing count-only test.
    travel(59)->minutes();

    postJson('/api/v1/auth/register', validRegistration(['email' => 'ada7@example.test']))
        ->assertStatus(429);
});

it('releases the registration throttle once the hour is up', function (): void {
    Mail::fake();

    foreach (range(1, 5) as $i) {
        postJson('/api/v1/auth/register', validRegistration(['email' => "ada{$i}@example.test"]))
            ->assertCreated();
    }

    travel(61)->minutes();

    postJson('/api/v1/auth/register', validRegistration(['email' => 'ada7@example.test']))
        ->assertCreated();
});

it('holds the forgot-password throttle for the whole hour, then releases it', function (): void {
    Mail::fake();

    $user = User::factory()->hasProfile()->create();

    foreach (range(1, 3) as $ignored) {
        postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();
    }

    postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(429);

    travel(59)->minutes();
    postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertStatus(429);

    travel(2)->minutes();
    postJson('/api/v1/auth/forgot-password', ['email' => $user->email])->assertAccepted();
});
