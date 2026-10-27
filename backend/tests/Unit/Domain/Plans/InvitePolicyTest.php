<?php

declare(strict_types=1);

use App\Domain\Plans\InviteOutcome;
use App\Domain\Plans\InvitePolicy;
use App\Domain\Plans\InviteState;

// No RefreshDatabase, no TestCase, no container: spec section 12 puts invite expiry,
// revocation and reuse in the domain unit tests precisely so it can be reasoned about without
// a database. tests/Pest.php binds TestCase only to Feature, so this file runs on plain
// PHPUnit, which is the point.
//
// What this file does NOT assert, and whose obligation it is: the resolver's check order -
// an invite that was accepted and has since expired must resolve to InviteState::Accepted,
// reporting reuse rather than expiry. InvitePolicy::decide() takes one InviteState and
// therefore cannot express a row that is both; the ordering belongs to
// PlanInviteTokens::stateOf(), written by a later section, and the assertion of that order is
// owed there.

it('accepts a valid invite', function (): void {
    expect(InvitePolicy::decide(InviteState::Valid))->toBe(InviteOutcome::Accept);
});

it('rejects an expired invite - links must be expirable', function (): void {
    expect(InvitePolicy::decide(InviteState::Expired))->toBe(InviteOutcome::Reject);
});

it('rejects a revoked invite - links must be revocable', function (): void {
    expect(InvitePolicy::decide(InviteState::Revoked))->toBe(InviteOutcome::Reject);
});

it('rejects an already-accepted invite - single use', function (): void {
    expect(InvitePolicy::decide(InviteState::Accepted))->toBe(InviteOutcome::Reject);
});

it('decides an outcome for every state, so a new state cannot be added silently', function (): void {
    // No ->throwsNoExceptions(): that modifier calls expectNotToPerformAssertions(), so
    // PHPUnit marks this test RISKY (RotationPolicyTest.php:54-63). The four expect() calls
    // above are the real assertions; this loop's own expect() calls are additional ones, not
    // the only ones, so an empty InviteState::cases() would still leave this test
    // non-trivially exercised by the four it()s above it. Adding `case Probe;` to InviteState
    // produces "UnhandledMatchError: Unhandled match case App\Domain\Plans\InviteState::Probe"
    // here, with or without the modifier.
    foreach (InviteState::cases() as $state) {
        expect(InvitePolicy::decide($state))->toBeInstanceOf(InviteOutcome::class);
    }
});
