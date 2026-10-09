<?php

declare(strict_types=1);

use App\Domain\Auth\RefreshTokenState;
use App\Domain\Auth\RotationOutcome;
use App\Domain\Auth\RotationPolicy;

// No RefreshDatabase, no TestCase, no container: spec §12 puts the rotation chain rule in the
// domain unit tests precisely so it can be reasoned about without a database. tests/Pest.php
// binds TestCase only to Feature, so this file runs on plain PHPUnit, which is the point.

it('rotates an active token', function (): void {
    expect(RotationPolicy::decide(RefreshTokenState::Active))->toBe(RotationOutcome::Rotate);
});

it('revokes the chain when an already-rotated token is presented', function (): void {
    // This is the stolen-token signal. The legitimate client rotated this token and moved on;
    // anything presenting it again is replaying a captured value, and the only safe response
    // is to kill the whole lineage - including the successor the thief may already hold.
    expect(RotationPolicy::decide(RefreshTokenState::Rotated))->toBe(RotationOutcome::RevokeChain);
});

it('revokes the chain when an explicitly revoked token is presented', function (): void {
    expect(RotationPolicy::decide(RefreshTokenState::Revoked))->toBe(RotationOutcome::RevokeChain);
});

it('rejects an expired token without revoking anything', function (): void {
    // Expiry is not evidence of theft - it is an app that sat in the background for a month.
    // Revoking the chain here would log out honest users and teach nothing.
    expect(RotationPolicy::decide(RefreshTokenState::Expired))->toBe(RotationOutcome::Reject);
});

it('decides an outcome for every state, so a new state cannot be added silently', function (): void {
    // No ->throwsNoExceptions(): that modifier calls expectNotToPerformAssertions(), so
    // PHPUnit marks this test RISKY ("not expected to perform assertions but performed 4")
    // - verified, it was. The four expect() calls below are the assertions, and an
    // unhandled case still fails the test on its own: adding `case Probe;` to
    // RefreshTokenState produced "UnhandledMatchError: Unhandled match case
    // App\Domain\Auth\RefreshTokenState::Probe" here, with and without the modifier.
    foreach (RefreshTokenState::cases() as $state) {
        expect(RotationPolicy::decide($state))->toBeInstanceOf(RotationOutcome::class);
    }
});
