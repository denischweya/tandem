<?php

declare(strict_types=1);

use App\Application\Plans\IssuedInvite;
use App\Domain\Plans\MemberRole;
use App\Models\PlanInvite;
use Illuminate\Support\Carbon;

// tests/Feature, not tests/Unit, for the reason PlanMemberViewTest (Task 12) already
// established for the sibling DTO: fromInvite() reads $invite->expires_at, ->accepted_at and
// ->revoked_at through PlanInvite's `datetime` cast, which needs a resolved connection on both
// the write side (HasAttributes::setAttribute() -> fromDateTime() -> getDateFormat() ->
// getConnection()) and the read side (asDateTime() -> the Date facade). tests/Unit boots
// neither - tests/Pest.php's single uses(TestCase::class)->in('Feature') line names Feature
// only. Measured: moving this fixture under tests/Unit throws "Call to a member function
// connection() on null" from inside vendor/laravel/framework's Model.php, before this file's
// own assertions ever run.
//
// A bare `new PlanInvite` plus forceFill(), never fill() and never PlanInvite::factory(): the
// model's $guarded is ['*'] (totallyGuarded()), so fill() throws MassAssignmentException on the
// first supplied key, and PlanInvite::factory()->make() drags Plan::factory() and
// User::factory() into resolution regardless of make() versus create() (task-14-context.md
// §1a) - neither matters here, since this fixture needs no Plan and no User row at all,
// id/email/role/expires_at/accepted_at/revoked_at being everything fromInvite() reads.
function planInviteFixtureForIssuedInvite(): PlanInvite
{
    $invite = new PlanInvite;

    $invite->forceFill([
        'id' => '0192f0a1-cccc-7000-8000-000000000011',
        'email' => 'invitee@example.test',
        'role' => MemberRole::Admin->value,
        'expires_at' => Carbon::parse('2026-10-20 00:00:00+00:00'),
        'accepted_at' => Carbon::parse('2026-10-21 00:00:00+00:00'),
        'revoked_at' => Carbon::parse('2026-10-22 00:00:00+00:00'),
    ]);

    return $invite;
}

// Eight separate expect() calls, not a chained expression and not a single toBe() against a
// second IssuedInvite: task-14-context.md §1c names three same-typed pairs on this class that a
// named-argument swap inside fromInvite()'s `new self(...)` call exchanges invisibly -
// acceptedAt/revokedAt (both ?Carbon), id/token (both string), email/invitedByName (both
// ?string) - and Pest aborts a test at its first failing assertion
// (plan-corrections.md [[H1]]), so a single chained expression would only ever prove the first
// clause a mutation reaches. Every value below is distinguishable from every other value of the
// same type, including from the other member of its own pair, so each swap is individually
// provable - see this file's break-step record in task-14-report.md.
it('maps every field of a PlanInvite onto the matching IssuedInvite field, with token and invitedByName passed through unchanged', function (): void {
    $invite = planInviteFixtureForIssuedInvite();

    $issued = IssuedInvite::fromInvite($invite, 'Ada Lovelace', 'plaintext-token-value');

    expect($issued->id)->toBe('0192f0a1-cccc-7000-8000-000000000011', 'id must come from $invite->id, not from the $token argument');
    expect($issued->email)->toBe('invitee@example.test', 'email must come from $invite->email, not from the $invitedByName argument');
    expect($issued->role)->toBe(MemberRole::Admin);
    expect($issued->expiresAt->toDateTimeString())->toBe('2026-10-20 00:00:00');
    expect($issued->acceptedAt?->toDateTimeString())->toBe('2026-10-21 00:00:00', 'acceptedAt must come from $invite->accepted_at, not $invite->revoked_at');
    expect($issued->revokedAt?->toDateTimeString())->toBe('2026-10-22 00:00:00', 'revokedAt must come from $invite->revoked_at, not $invite->accepted_at');
    expect($issued->invitedByName)->toBe('Ada Lovelace', 'invitedByName must come from the $invitedByName argument, not from $invite->email');
    expect($issued->token)->toBe('plaintext-token-value', 'token must come from the $token argument, not from $invite->id');
});
