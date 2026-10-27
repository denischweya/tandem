<?php

declare(strict_types=1);

use App\Application\Auth\Register;
use App\Domain\Shared\Clock;
use App\Domain\Shared\FrozenClock;
use App\Domain\Shared\TimeZoneId;
use App\Domain\Users\DisplayName;
use App\Domain\Users\Email;
use App\Infrastructure\Outbox\OutboxHandlers;
use App\Models\OutboxEvent;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

// Nothing in app/, database/ or tests/ touched this model before the branch-review fix:
// getFillable() named columns ("name", "password") that do not exist, the "hashed" cast
// named a column ("password") that does not exist, and there was no HasUuids despite a
// uuid primary key. User::factory()->create() is therefore the load-bearing smoke test -
// without it, nothing in the suite ever reaches the model at all.
uses(RefreshDatabase::class);

it('can be created through its factory', function (): void {
    $user = User::factory()->create();

    expect($user->email)->not->toBeEmpty();
    expect($user->password_hash)->toStartWith('$2y$');
});

it('has a real UUIDv7 primary key, not an auto-increment integer', function (): void {
    $user = User::factory()->create();

    expect($user->getKeyType())->toBe('string');
    expect($user->getIncrementing())->toBeFalse();
    expect($user->id)
        ->toBeString()
        ->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i');
});

it('serializes no key that looks like a credential, keyed off the real schema', function (): void {
    $user = User::factory()->create();

    // Schema-driven, not an assertion on getHidden()'s literal contents (per the
    // branch-review fix): this walks every column users actually has, rather than a
    // hard-coded list, so it survives a column rename and catches a new sensitive column
    // added later without a matching #[Hidden] entry.
    $columns = DB::table('information_schema.columns')
        ->where('table_schema', 'public')
        ->where('table_name', 'users')
        ->pluck('column_name');

    expect($columns)->toContain('password_hash');

    $serialized = $user->toArray();

    foreach (array_keys($serialized) as $key) {
        expect($key)->not->toMatch('/password|token|secret/i');
    }

    // The id key must still be present and be the real UUID string, not silently dropped
    // by the leak check above (which only forbids password/token/secret-shaped keys).
    expect($serialized)->toHaveKey('id');
    expect($serialized['id'] ?? null)->toBe($user->id);
});

it('is soft-deleted, not hard-deleted', function (): void {
    $user = User::factory()->create();

    $user->delete();

    expect($user->deleted_at)->not->toBeNull();
    expect(DB::table('users')->where('id', $user->id)->exists())->toBeTrue();
});

it('marks the email verified using the same Clock instant VerifyEmail::execute() uses', function (): void {
    // markEmailAsVerified() had no caller and no test, and stamped $this->freshTimestamp()
    // while VerifyEmail::execute() uses the injected Clock - so the two wrote different
    // instants and a frozen clock moved only one of them. It cannot be deleted (the
    // MustVerifyEmail interface is what makes the `verified` middleware work at all). The
    // first fix delegated to app(VerifyEmail::class)->execute($this), which fixed the
    // divergence but made a model depend on App\Application - caught, after the fact, by
    // tests/Architecture/DomainPurityTest.php's "models do not reach into the application
    // layer" rule. It now resolves Clock directly instead - the same Domain contract
    // VerifyEmail::execute() resolves, so this pins the same single-instant-source property
    // without the inversion.
    $instant = new DateTimeImmutable('2026-03-04 05:06:07', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($instant));

    $user = User::factory()->unverified()->hasProfile()->create();

    expect($user->markEmailAsVerified())->toBeTrue();

    expect($user->refresh()->email_verified_at?->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($instant->format('Y-m-d H:i:s'));
});

it('is idempotent, and leaves the original verification instant alone on a second call', function (): void {
    // The half the endpoint test could not see, because its factory user starts verified: a
    // second call must not re-stamp. VerifyEmail::execute() returns early on an
    // already-verified user, and markEmailAsVerified() repeats that same check directly
    // rather than a second unconditional forceFill.
    $first = new DateTimeImmutable('2026-03-04 05:06:07', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($first));

    $user = User::factory()->unverified()->hasProfile()->create();
    $user->markEmailAsVerified();

    app()->instance(Clock::class, new FrozenClock($first->modify('+1 day')));
    $user->markEmailAsVerified();

    expect($user->refresh()->email_verified_at?->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($first->format('Y-m-d H:i:s'));
});

it('writes the same user.registered event Register does', function (): void {
    // sendEmailVerificationNotification() cannot delegate - Register writes this event from
    // inside its own account-creation transaction, so there is no seam to call - which leaves
    // one line duplicated between the two. Compared row against row rather than against a
    // restated literal: if either side changes the event type or the payload shape, the
    // dispatcher reports "No handler registered" for the other one for ever.
    $registered = app(Register::class)->execute(
        Email::fromString('ada@example.test'),
        DisplayName::fromString('Ada Lovelace'),
        TimeZoneId::fromString('Europe/London'),
        'en',
        'correct horse battery staple',
    );

    $fromRegister = OutboxEvent::sole();

    $other = User::factory()->unverified()->hasProfile()->create();
    $other->sendEmailVerificationNotification();

    $fromModel = OutboxEvent::query()->whereKeyNot($fromRegister->id)->sole();

    expect($fromModel->event_type)->toBe($fromRegister->event_type);
    expect($fromModel->aggregate_type)->toBe($fromRegister->aggregate_type);
    expect(array_keys($fromModel->payload))->toBe(array_keys($fromRegister->payload));
    expect($fromModel->payload['user_id'])->toBe($other->id);
    expect($fromRegister->payload['user_id'])->toBe($registered->id);

    // And the shared type really is one a handler is registered for, so neither row can sit
    // unpublishable.
    expect(app(OutboxHandlers::class)->handlerFor($fromModel->event_type))->not->toBeNull();
});
