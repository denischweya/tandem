<?php

declare(strict_types=1);

namespace App\Domain\Auth;

/**
 * Pure: no database, no framework, no clock. Expiry has already been resolved into
 * RefreshTokenState by the caller, which is what keeps this decidable from an enum alone and
 * therefore unit-testable with no fixtures (spec §12).
 */
final class RotationPolicy
{
    public static function decide(RefreshTokenState $state): RotationOutcome
    {
        // match, not a chain of ifs: adding a case to RefreshTokenState without handling it
        // here throws UnhandledMatchError rather than silently falling through to a default,
        // which is what makes the exhaustiveness test above meaningful.
        return match ($state) {
            RefreshTokenState::Active => RotationOutcome::Rotate,

            // Rotated, and ONLY Rotated, is the replay signal. Spec §9.1 scopes chain
            // revocation to "an already-rotated refresh token", and the distinction is the
            // whole of the rule rather than a detail: a rotated token proves a successor was
            // issued, so presenting it again means two parties hold the lineage, and the only
            // safe answer is to kill the lineage - including the successor the thief may
            // already hold - and every access token minted from it (§9.1.1).
            RefreshTokenState::Rotated => RotationOutcome::RevokeChain,

            // Revoked was mapped to RevokeChain alongside Rotated, with nothing recorded about
            // why, and that made a dead credential into a remote sign-out weapon. Revoked-and
            // -not-rotated is what Logout::execute(), ResetPassword and DeleteAccount all
            // produce, and revoked rows are never deleted - so every refresh token a user has
            // ever killed stays in this state for ever. RefreshSession's RevokeChain branch
            // then deletes every access token the user holds, which meant anyone who had ever
            // captured ANY of that user's refresh tokens could sign them out everywhere on
            // demand: one unauthenticated, unthrottled request, repeatable for as long as they
            // cared to. The benign version of the same thing is a mobile client whose
            // single-flight refresh races its own logout and signs the user out of every
            // device.
            //
            // A revoked, unrotated token proves only that someone holds a credential that is
            // already dead. Revoking a chain that is already revoked buys nothing to set
            // against that, so this rejects and does nothing else.
            //
            // Replay detection loses nothing, because the two conditions are not exclusive:
            // RefreshTokenRotator::stateOf() checks replaced_by_id BEFORE revoked_at, so every
            // ancestor of a partly-revoked chain still reports Rotated and still triggers the
            // branch above. Asserted, not assumed - see tests/Feature/Auth/RefreshTest.php's
            // "still detects a replayed ancestor after the live tail was revoked by a logout".
            RefreshTokenState::Revoked => RotationOutcome::Reject,

            // Expiry is not evidence of theft - it is an app that sat in the background for a
            // month. Revoking the chain here would log out honest users and teach nothing.
            RefreshTokenState::Expired => RotationOutcome::Reject,
        };
    }
}
