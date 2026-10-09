<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Auth\RefreshTokenState;
use App\Domain\Shared\Clock;
use App\Models\RefreshToken;
use App\Models\User;

final readonly class RefreshTokenRotator
{
    public function __construct(
        private RefreshTokenIssuer $issuer,
        private Clock $clock,
    ) {}

    /**
     * Order matters. A rotated token is also revoked (rotate() sets both), so checking
     * revoked_at first would report every rotated token as Revoked - the same outcome today,
     * but it would make the Rotated state unreachable and quietly untested.
     */
    public function stateOf(RefreshToken $token): RefreshTokenState
    {
        if ($token->replaced_by_id !== null) {
            return RefreshTokenState::Rotated;
        }

        if ($token->revoked_at !== null) {
            return RefreshTokenState::Revoked;
        }

        if ($token->expires_at->toDateTimeImmutable() <= $this->clock->now()) {
            return RefreshTokenState::Expired;
        }

        return RefreshTokenState::Active;
    }

    /**
     * $user is passed in rather than read back off $token->user, because the caller has
     * already had to resolve it and prove it is not null - the relation respects the
     * SoftDeletes global scope, so re-reading it here would be a second nullable lookup
     * whose null case this method has no way to answer.
     */
    public function rotate(RefreshToken $token, User $user): IssuedRefreshToken
    {
        $successor = $this->issuer->issue($user, $token->device_id === null ? null : $token->device);

        $token->forceFill([
            'revoked_at' => $this->clock->now(),
            'replaced_by_id' => $successor->model->id,
        ])->save();

        return $successor;
    }

    /**
     * Walks the chain forward from the presented token, revoking every row including the one
     * presented. Forward is sufficient: ancestors are already revoked (rotate() revokes as it
     * replaces), and the live token is always a descendant.
     *
     * @return int how many rows were revoked
     */
    public function revokeChainFrom(RefreshToken $token): int
    {
        $revoked = 0;
        $now = $this->clock->now();
        $current = $token;
        // A cycle cannot occur - replaced_by_id always points at a row created later - but the
        // bound costs nothing and turns a corrupted chain into a bounded loop rather than a
        // hung request.
        $guard = 0;

        while ($guard++ < 1000) {
            if ($current->revoked_at === null) {
                $current->forceFill(['revoked_at' => $now])->save();
                $revoked++;
            }

            $next = $current->replaced_by_id === null ? null : $current->replacedBy;

            if ($next === null) {
                break;
            }

            $current = $next;
        }

        return $revoked;
    }
}
