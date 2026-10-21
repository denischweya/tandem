<?php

declare(strict_types=1);

use App\Application\Plans\InviteRepository;
use App\Domain\Plans\InviteState;
use App\Domain\Plans\MemberRole;
use App\Domain\Shared\Clock;
use App\Domain\Shared\FrozenClock;
use App\Domain\Users\Email;
use App\Infrastructure\Auth\PlanInviteTokens;
use App\Models\PlanInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('issues a 64-lowercase-hex token and stores only its sha256', function (): void {
    $owner = User::factory()->hasProfile(['display_name' => 'Ada Lovelace'])->create();
    $plan = ownedPlan($owner);

    $issued = app(PlanInviteTokens::class)->issue($plan, $owner, null, MemberRole::Member);

    // 64 lowercase hex characters, 256 bits - byte for byte the shape of both existing
    // credentials of this kind. A length assertion alone would pass for base64.
    expect($issued->token)->toMatch('/^[0-9a-f]{64}$/');
    expect($issued->invite->token_hash)->toBe(hash('sha256', $issued->token));

    // The plaintext must not be recoverable from the row. Asserted against every column,
    // because a future field that happened to carry it would otherwise be invisible here.
    $row = PlanInvite::query()->sole();
    expect(json_encode($row->getAttributes()))->not->toContain($issued->token);

    expect($issued->inviterName)->toBe('Ada Lovelace');
    expect($issued->invite->email)->toBeNull();
    expect($issued->invite->role)->toBe(MemberRole::Member->value);
    expect($issued->invite->invited_by)->toBe($owner->id);
    expect($issued->invite->plan_id)->toBe($plan->id);
    expect($issued->invite->accepted_at)->toBeNull();
    expect($issued->invite->revoked_at)->toBeNull();
});

it('expires an issued invite fourteen days after it is issued', function (): void {
    // The number is asserted against the decision directly - 14 days is a Global Constraint of
    // this section, so pinning the literal is pinning the decision rather than keeping a second
    // copy of it - and the MECHANISM is asserted separately against the constant, so a constant
    // changed on purpose moves one assertion rather than silently disabling the behaviour.
    expect(PlanInviteTokens::TTL_DAYS)->toBe(14);

    // A frozen clock, so "fourteen days from issue" is exact rather than approximate: issue()
    // computes the expiry from Clock::now() and this assertion computes it from the same
    // instant. travel() cannot reach it - SystemClock builds `new DateTimeImmutable('now')`
    // because app/Domain imports nothing from Illuminate, so it never consults
    // Carbon::setTestNow, which is what travel() moves. Clock::class is bound, not a singleton
    // (AppServiceProvider::register()), so this rebind is picked up by the resolve below.
    $instant = new DateTimeImmutable('now', new DateTimeZone('UTC'));
    app()->instance(Clock::class, new FrozenClock($instant));

    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    $issued = app(PlanInviteTokens::class)->issue($plan, $owner, null, MemberRole::Member);

    // Second precision: the column is timestamptz(6) and the clock carries microseconds, but
    // the comparison is against a value this test computed from the same instant, so seconds
    // are what the assertion is about.
    expect($issued->invite->expires_at->toDateTimeImmutable()->format('Y-m-d H:i:s'))
        ->toBe($instant->modify('+'.PlanInviteTokens::TTL_DAYS.' days')->format('Y-m-d H:i:s'));
});

it('stores a normalised email for an emailed invite and null for a link-only one', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    $tokens = app(PlanInviteTokens::class);

    $emailed = $tokens->issue($plan, $owner, Email::fromString('Ada@Example.Test'), MemberRole::Member);
    $linkOnly = $tokens->issue($plan, $owner, null, MemberRole::Admin);

    // plan_invites carries a byte-exact CHECK - email IS NULL OR email = lower(email) - so a
    // mixed-case address is a 23514 constraint violation surfacing as a 500. Email::fromString()
    // lowercases, which is why the write above does not throw.
    expect($emailed->invite->email)->toBe('ada@example.test');
    expect($linkOnly->invite->email)->toBeNull();
    expect($linkOnly->invite->role)->toBe(MemberRole::Admin->value);
});

it('does not supersede an outstanding invite when a second one is issued', function (): void {
    // The opposite of PasswordResetTokens::issue(), and the asymmetry has a reason. A reset
    // supersedes because requesting a second reset is a statement that the first email is not
    // the one being acted on - one account, one live reset. An invite names a DIFFERENT PERSON
    // each time, so superseding would mean inviting Sarah kills the link already sent to James.
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);
    $tokens = app(PlanInviteTokens::class);

    $first = $tokens->issue($plan, $owner, Email::fromString('james@example.test'), MemberRole::Member);
    $tokens->issue($plan, $owner, Email::fromString('sarah@example.test'), MemberRole::Member);

    expect(PlanInvite::query()->count())->toBe(2);
    expect($tokens->stateOf($first->invite->refresh()))->toBe(InviteState::Valid);
});

it('resolves accepted before expired, so a used invite that has since expired reports reuse', function (): void {
    // The check order is a design decision rather than an implementation detail, and it is
    // asserted rather than read off the method. This mirrors PasswordResetTokens::stateOf()
    // (used before expired) and RefreshTokenRotator::stateOf() (replaced_by_id before
    // revoked_at) - and the latter's ordering is why replay detection survived the
    // Revoked => Reject change, which is this codebase's clearest demonstration that the order
    // of a state resolver is a design decision.
    $invite = PlanInvite::factory()->expired()->accepted()->create();

    expect(app(PlanInviteTokens::class)->stateOf($invite))->toBe(InviteState::Accepted);
});

it('resolves revoked before expired, so a revoked invite that has since expired reports revocation', function (): void {
    $invite = PlanInvite::factory()->expired()->revoked()->create();

    expect(app(PlanInviteTokens::class)->stateOf($invite))->toBe(InviteState::Revoked);
});

it('resolves each single condition to its own state', function (): void {
    $tokens = app(PlanInviteTokens::class);

    expect($tokens->stateOf(PlanInvite::factory()->create()))->toBe(InviteState::Valid);
    expect($tokens->stateOf(PlanInvite::factory()->expired()->create()))->toBe(InviteState::Expired);
    expect($tokens->stateOf(PlanInvite::factory()->revoked()->create()))->toBe(InviteState::Revoked);
    expect($tokens->stateOf(PlanInvite::factory()->accepted()->create()))->toBe(InviteState::Accepted);
});

it('refuses mass assignment on the invite model', function (): void {
    // The mechanism, not a comment: $guarded = ['*'] makes totallyGuarded() true, at which
    // point Model::fill() THROWS on the first attribute rather than discarding it silently.
    // Re-adding `protected $guarded = []` fails this. role matters most - an owner-role invite
    // would be an ownership transfer by the back door.
    expect(fn () => (new PlanInvite)->fill(['role' => 'owner']))
        ->toThrow(MassAssignmentException::class);
    expect(fn () => (new PlanInvite)->fill(['token_hash' => 'x']))
        ->toThrow(MassAssignmentException::class);
});

it('generates a uuid primary key for an invite row', function (): void {
    // HasUuids, or plan_invites.id arrives null: the v7 generation is Laravel 13.35.0's own
    // trait (HasUuids::newUniqueId() returns (string) Str::uuid7()) and there is no project
    // override to verify. What there is to pin is that this model carries the trait.
    $invite = PlanInvite::factory()->create();

    expect($invite->id)->toMatch('/^[0-9a-f]{8}-[0-9a-f]{4}-7[0-9a-f]{3}-[0-9a-f]{4}-[0-9a-f]{12}$/');
});

// Amendment 8's owed pairing. Task 7's PlanInviteFactory::forToken() computes
// hash('sha256', $plainToken) to seed token_hash, and the brief's own first case above
// recomputes that same expression INLINE against the issuer's own output - which proves the
// issuer self-consistent and says nothing about the factory. A disagreement between the two
// would make every invite fixture in the suite a token no lookup can find, surfacing as "the
// invite is not found" rather than "the hash differs" - so the pairing is asserted directly
// here: one plaintext, hashed both ways, then looked up through the real repository.
it('issues a token the factory can seed a findable row for', function (): void {
    $owner = User::factory()->hasProfile()->create();
    $plan = ownedPlan($owner);

    $issued = app(PlanInviteTokens::class)->issue($plan, $owner, null, MemberRole::Member);
    $issuerHash = $issued->invite->token_hash;

    // plan_invites.token_hash is UNIQUE (measured: database/migrations/2026_10_06_000200_
    // create_plan_tables.php declares $table->string('token_hash')->unique()), so the row
    // issue() just wrote and a factory-seeded row for the SAME plaintext cannot coexist -
    // deleting it first is what lets this test assert the pairing Amendment 8 owes without
    // colliding with that constraint. (Amendment 8's own prescribed form of this case keeps
    // both rows alive and cannot run against the real schema: SQLSTATE[23505] duplicate key
    // on plan_invites_token_hash_unique, observed before this rewrite.)
    $issued->invite->delete();

    $seeded = PlanInvite::factory()->forToken($issued->token)->create();

    // The pairing Amendment 8 owes: the factory's hashing expression against the issuer's, on
    // one plaintext. Not the issuer against itself.
    expect($seeded->token_hash)->toBe($issuerHash);

    // And the half that proves the pairing MATTERS rather than merely holding: a factory-seeded
    // row is findable by the plaintext through the production lookup.
    expect(app(InviteRepository::class)->findByToken($issued->token)?->id)->toBe($seeded->id);
});
