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
            RefreshTokenState::Rotated, RefreshTokenState::Revoked => RotationOutcome::RevokeChain,
            RefreshTokenState::Expired => RotationOutcome::Reject,
        };
    }
}
