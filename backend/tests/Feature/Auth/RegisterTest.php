<?php

declare(strict_types=1);

use App\Application\Auth\Register;
use App\Domain\Shared\TimeZoneId;
use App\Domain\Users\DisplayName;
use App\Domain\Users\Email;
use App\Infrastructure\Notifications\Mail\VerifyEmailMail;
use App\Infrastructure\Outbox\OutboxDispatcher;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;

use function Pest\Laravel\postJson;

uses(RefreshDatabase::class);

// No RateLimiter::clear('auth-register') here. It was a no-op dressed as protection: the
// named-limiter middleware stores its bucket under md5($limiterName.$limit->key)
// (Illuminate\Routing\Middleware\ThrottleRequests::handleRequestUsingNamedLimiter()), so clearing
// the bare string 'auth-register' touched a key the limiter never writes. Nothing is needed in
// its place: CACHE_STORE is 'array' under phpunit.xml and the application is rebuilt per test, so
// limiter state never crosses a test boundary.
// tests/Feature/RateLimit/LimiterKeyHygieneTest.php is the gate that keeps it deleted.
beforeEach(function (): void {
    Mail::fake();
});

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

it('rejects an address email:rfc accepts but the domain value object rejects', function (): void {
    // The two validators disagreed: 'email:rfc' accepts ada@localhost (a plausible intranet
    // address), filter_var(FILTER_VALIDATE_EMAIL) does not, and RegisterController calls
    // Email::fromString() with no try/catch - so every address in that gap left the
    // controller as a 500 instead of a 422.
    postJson('/api/v1/auth/register', validRegistration(['email' => 'ada@localhost']))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('email');

    expect(User::count())->toBe(0);
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

it('rejects a whitespace-only display name with a 422, not a 500', function (): void {
    // DisplayName::fromString('   ') throws InvalidArgumentException, and
    // RegisterController calls it with no try/catch. Without trimming in
    // RegisterRequest::prepareForValidation(), `required` never sees the empty string and
    // this would surface as a 500 instead.
    postJson('/api/v1/auth/register', validRegistration(['display_name' => '   ']))
        ->assertStatus(422)
        ->assertJsonValidationErrorFor('display_name');

    expect(User::count())->toBe(0);
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

it('turns a unique-constraint race at the database into a 422, not a 500', function (): void {
    // RegisterRequest's `unique:users,email` rule does a SELECT before the use case's INSERT
    // runs - two concurrent registrations for the same case-folded address can both pass that
    // SELECT and then collide on the real users_email_unique constraint. RefreshDatabase wraps
    // a single connection, so true concurrency can't be reproduced here. Instead this calls
    // Register::execute() directly, bypassing RegisterRequest's `unique` rule the way a real
    // race would - the use case still has to reach the real INSERT with the email already
    // present, and Postgres still throws the real UniqueConstraintViolationException.
    User::factory()->create(['email' => 'ada@example.test']);

    try {
        app(Register::class)->execute(
            Email::fromString('ada@example.test'),
            DisplayName::fromString('Ada Lovelace'),
            TimeZoneId::fromString('Europe/London'),
            'en',
            'correct horse battery staple',
        );

        expect(false)->toBeTrue('Expected Register::execute() to throw a ValidationException.');
    } catch (ValidationException $e) {
        expect($e->status)->toBe(422);
        expect($e->errors())->toHaveKey('email');
    }

    // Still exactly one row: the colliding insert rolled back along with its profile and
    // outbox write, inside the same transaction.
    expect(User::count())->toBe(1);
});

it('refuses an empty or whitespace-only locale, which the rule now says as well as does', function (): void {
    // The rule was ['sometimes', 'string', 'max:10'], which READS as "an empty string is
    // fine" and never was: Laravel's global middleware reaches the value before any rule does,
    // TrimStrings turns '   ' into '', ConvertEmptyStringsToNull turns '' into null, and null
    // fails `string`. So this is a 422 and always was - adding `required` alongside
    // `sometimes` (matching UpdateProfileRequest) changes the rule's description of itself,
    // not its behaviour.
    //
    // That difference mattered outside this repo: tandem-shared's RegisterSchema mirrored the
    // written rule and accepted '', so a locale no client could successfully send was one the
    // client-side validator said was fine. Asserted here so the two cannot drift apart again
    // in whichever direction someone edits next.
    foreach (['', '   '] as $locale) {
        postJson('/api/v1/auth/register', validRegistration(['locale' => $locale]))
            ->assertStatus(422)
            ->assertJsonValidationErrorFor('locale');
    }
});

it('still treats locale as optional, and defaults it when omitted', function (): void {
    // `sometimes` is the half that must survive adding `required`: omitting the key entirely
    // is valid, and RegisterController supplies 'en'. Without this, the two rules together
    // could be read as making the field mandatory.
    $payload = validRegistration();
    unset($payload['locale']);

    postJson('/api/v1/auth/register', $payload)
        ->assertCreated()
        ->assertJsonPath('data.locale', 'en');
});

it('accepts an address with leading or trailing whitespace, as TrimStrings leaves it', function (): void {
    // The server half of a cross-repo agreement, pinned here so neither side can drift alone.
    // TrimStrings runs before every rule on every api/* request, so this address reaches
    // ValidEmail already trimmed and NormalizesEmailInput lowercases it - it registers, and the
    // stored address is the normalised one. tandem-shared's apiEmail regex rejected exactly
    // this input until its own fix, which made an address the API accepts unusable through
    // either client.
    postJson('/api/v1/auth/register', validRegistration(['email' => '  Ada@Example.test  ']))
        ->assertCreated()
        ->assertJsonPath('data.email', 'ada@example.test');

    expect(User::query()->where('email', 'ada@example.test')->exists())->toBeTrue();
});
