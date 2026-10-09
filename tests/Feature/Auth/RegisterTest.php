<?php

declare(strict_types=1);

use App\Infrastructure\Notifications\Mail\VerifyEmailMail;
use App\Infrastructure\Outbox\OutboxDispatcher;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Mail::fake();
    RateLimiter::clear('auth-register');
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function validRegistration(array $overrides = []): array
{
    return array_merge([
        'email' => 'ada@example.test',
        'password' => 'correct horse battery staple',
        'display_name' => 'Ada Lovelace',
        'timezone' => 'Europe/London',
        'locale' => 'en',
    ], $overrides);
}

it('creates a user and a profile and returns the public shape', function (): void {
    postJson('/api/v1/auth/register', validRegistration())
        ->assertCreated()
        ->assertJsonPath('data.email', 'ada@example.test')
        ->assertJsonPath('data.display_name', 'Ada Lovelace')
        ->assertJsonPath('data.timezone', 'Europe/London')
        ->assertJsonPath('data.email_verified', false)
        ->assertJsonStructure(['data' => ['id', 'email', 'email_verified', 'display_name', 'timezone', 'locale']]);

    $user = User::sole();

    expect($user->profile->display_name)->toBe('Ada Lovelace');
    expect($user->email_verified_at)->toBeNull();
});

it('never returns a token from registration', function (): void {
    $body = postJson('/api/v1/auth/register', validRegistration())->json();

    // Registration deliberately does not mint a session: exactly one code path does, and
    // every issuance security test lives there. See the task note.
    expect(json_encode($body))->not->toContain('access_token');
    expect(json_encode($body))->not->toContain('refresh_token');
});

it('stores the password only as a bcrypt hash', function (): void {
    postJson('/api/v1/auth/register', validRegistration())->assertCreated();

    $user = User::sole();

    expect($user->password_hash)->toStartWith('$2y$');
    expect($user->password_hash)->not->toContain('correct horse');
});

it('lowercases the email before storing it, satisfying the database CHECK', function (): void {
    postJson('/api/v1/auth/register', validRegistration(['email' => 'Ada@Example.TEST']))
        ->assertCreated();

    expect(User::sole()->email)->toBe('ada@example.test');
});

it('rejects a duplicate email case-insensitively', function (): void {
    postJson('/api/v1/auth/register', validRegistration())->assertCreated();

    postJson('/api/v1/auth/register', validRegistration(['email' => 'ADA@example.test']))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('email');
});

it('writes one outbox event and sends the verification mail through the dispatcher', function (): void {
    postJson('/api/v1/auth/register', validRegistration())->assertCreated();

    $event = OutboxEvent::sole();
    expect($event->event_type)->toBe('user.registered');
    expect($event->published_at)->toBeNull();

    // No mail yet: the whole point of the outbox is that the side effect happens after the
    // commit, from the dispatcher, not inline in the request.
    Mail::assertNothingSent();

    $summary = app(OutboxDispatcher::class)->dispatchPending();

    expect($summary->published)->toBe(1);
    Mail::assertSent(VerifyEmailMail::class, fn (VerifyEmailMail $mail) => $mail->hasTo('ada@example.test'));
});

it('rejects a password shorter than twelve characters', function (): void {
    postJson('/api/v1/auth/register', validRegistration(['password' => 'short']))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('password');
});

it('rejects a timezone that is not an IANA identifier', function (): void {
    postJson('/api/v1/auth/register', validRegistration(['timezone' => 'Mars/Olympus_Mons']))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('timezone');
});

it('rejects a display name longer than eighty characters', function (): void {
    postJson('/api/v1/auth/register', validRegistration(['display_name' => str_repeat('a', 81)]))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('display_name');
});

it('creates nothing at all when validation fails', function (): void {
    postJson('/api/v1/auth/register', validRegistration(['timezone' => 'nope']))->assertStatus(422);

    expect(User::count())->toBe(0);
    expect(OutboxEvent::count())->toBe(0);
});

it('rate limits registration to five per hour per IP', function (): void {
    foreach (range(1, 5) as $i) {
        postJson('/api/v1/auth/register', validRegistration(['email' => "user{$i}@example.test"]))
            ->assertCreated();
    }

    postJson('/api/v1/auth/register', validRegistration(['email' => 'six@example.test']))
        ->assertStatus(429);
});
