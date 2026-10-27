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

it('rejects an explicitly revoked token without revoking anything', function (): void {
    // Revoked is NOT the replay signal, and treating it as one made a dead credential into a
    // live weapon. Revoked-and-not-rotated is produced by Logout::execute(), ResetPassword and
    // DeleteAccount; revoked rows are never deleted, so every refresh token the user has ever
    // killed stays in this state for ever. Mapping it to RevokeChain meant RefreshSession went
    // on to delete every access token the user held - so anyone who had ever captured any of
    // their refresh tokens, including one they logged out of a year ago, could sign them out
    // everywhere on demand, from one unauthenticated, unthrottled, repeatable request. The
    // benign version is a mobile client whose single-flight refresh races its own logout.
    //
    // Spec §9.1 scopes chain revocation to "an already-rotated refresh token", and that is the
    // whole of it: a rotated token proves a successor was issued, so presenting it again means
    // two parties hold the lineage. A revoked, unrotated token proves only that someone holds
    // a credential that is already dead, and revoking a chain that is already revoked buys
    // nothing to set against the cost above.
    //
    // Nothing is lost from replay detection, because the two conditions are not exclusive:
    // RefreshTokenRotator::stateOf() checks replaced_by_id BEFORE revoked_at, so every
    // ancestor of a partly-revoked chain still reports Rotated. See
    // tests/Feature/Auth/RefreshTest.php's "still detects a replayed ancestor after the live
    // tail was revoked by a logout", which exercises exactly that row.
    expect(RotationPolicy::decide(RefreshTokenState::Revoked))->toBe(RotationOutcome::Reject);
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
