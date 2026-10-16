<?php

declare(strict_types=1);

use App\Domain\Plans\MemberRole;
use App\Models\Plan;
use App\Models\PlanInvite;
use App\Models\User;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('creates a persistable plan_invites row from the factory', function (): void {
    $invite = PlanInvite::factory()->create();

    expect($invite->id)->not->toBeNull();
    expect($invite->plan_id)->not->toBeNull();
    expect($invite->role)->toBe(MemberRole::Member->value);
    expect($invite->accepted_at)->toBeNull();
    expect($invite->revoked_at)->toBeNull();
    expect($invite->expires_at->isFuture())->toBeTrue();
    expect(strlen($invite->token_hash))->toBe(64);
});

it('gives the row a uuid primary key rather than leaving it null', function (): void {
    // HasUuids, not a database default: plan_invites.id has no default, so a model without the
    // trait sends NULL and Postgres refuses. 12 of 12 existing models carry the trait.
    $invite = PlanInvite::factory()->create();

    expect(Str::isUuid($invite->id))->toBeTrue();
});

it('resolves plan_invites as the table and reaches its Plan', function (): void {
    $plan = Plan::factory()->create();
    $invite = PlanInvite::factory()->create(['plan_id' => $plan->id]);

    expect($invite->getTable())->toBe('plan_invites');
    expect($invite->plan->id)->toBe($plan->id);
});

it('refuses to mass-assign any attribute on PlanInvite', function (): void {
    // plan_invites.role decides whether the accepting user becomes an admin, and token_hash is
    // a credential, so this model takes Plan's posture rather than PasswordResetToken's.
    // ['*'] makes totallyGuarded() true, which is the condition Model::fill() throws on; an
    // empty #[Fillable] allowlist permits everything instead - see Plan's docblock for that
    // measurement.
    expect((new PlanInvite)->totallyGuarded())->toBeTrue();

    foreach (['plan_id', 'role', 'token_hash', 'email', 'accepted_by', 'invited_by'] as $column) {
        expect(fn () => (new PlanInvite)->fill([$column => 'anything']))
            ->toThrow(MassAssignmentException::class);
    }
});

it('is refused by the database for a mixed-case email', function (): void {
    // plan_invites_email_lowercase, measured in the migration at line 121. §10.4's invite
    // request must normalise through EmailInput::normalize() or this is a reachable 500 on an
    // endpoint, not a test failure. ConstraintTest proves the same CHECK through raw SQL; this
    // proves it on the path Wave 2 actually writes through.
    //
    // Two details stop this passing for the wrong reason. toThrow()'s second argument is a
    // substring of the exception message - Pest runs assertStringContainsString with it before
    // assertInstanceOf - so the assertion names the constraint that has to fire: a bare
    // ->toThrow(QueryException::class) is satisfied by ANY database error, including a
    // column-name typo in this very test. And `id` is supplied here rather than left to
    // HasUuids, so this test fails for the CHECK and for nothing else: without it, removing
    // HasUuids from the model (step A7) makes the insert a NOT NULL violation on `id`, which is
    // also a QueryException, and this test would then be green for a reason that has nothing to
    // do with email.
    expect(fn () => PlanInvite::factory()->create([
        'id' => (string) Str::uuid7(),
        'email' => 'Ada@Example.test',
    ]))->toThrow(QueryException::class, 'plan_invites_email_lowercase');
});

it('keeps the invite row when the inviter is hard-deleted, and nulls invited_by', function (): void {
    // nullOnDelete, not cascade: "hard-deleting the sender must not delete the invite record".
    // This is the reachable path that makes PlanInviteResource.invited_by_name nullable - the
    // day-30 purge forceDelete()s the user and this column goes NULL under an invite that
    // GET /plans/{plan}/invites still lists.
    $inviter = User::factory()->hasProfile()->create();
    $invite = PlanInvite::factory()->create(['invited_by' => $inviter->id]);

    $inviter->forceDelete();

    expect($invite->refresh()->invited_by)->toBeNull();
});
