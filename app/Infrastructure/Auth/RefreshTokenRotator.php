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
     * Every hop is a LOCKING read, which is the point of this method rather than a precaution.
     * RefreshSession locks only the row that was presented; on the normal replay path that row
     * is an ancestor, so a plain SELECT for each subsequent hop reads a READ COMMITTED snapshot
     * - and readers do not block on FOR UPDATE in Postgres. The losing interleaving:
     *
     *   T2 refreshes tail C: takes FOR UPDATE on C, sees Active, and starts minting successor D
     *     and an access token, holding C's row lock for the whole of that work.
     *   T1 replays ancestor A: walks A -> B -> C, reads C unblocked, and sees
     *     revoked_at = NULL, replaced_by_id = NULL.
     *   T1's UPDATE of C then blocks on T2. T2 commits (C revoked, replaced_by_id = D). T1's
     *     update proceeds - harmless in itself - but T1's in-memory replaced_by_id is still
     *     NULL, so the loop breaks and D is never revoked.
     *
     * T1 then returns its 401 having left a live refresh token in circulation, which is exactly
     * what this method exists to prevent, and the window is the whole duration of the concurrent
     * refresh rather than an instruction-level sliver. lockForUpdate() both serialises against
     * that rotation and reads the latest committed row instead of a snapshot.
     *
     * No deadlock is introduced: every walker takes locks in chain order (ancestor before
     * descendant), and a refresh locks only the row it was handed, so no cycle can form.
     *
     * The race is NOT reproduced by the suite, and cannot be: RefreshDatabase wraps each test
     * in one transaction on one connection, so no single-connection assertion can express two
     * transactions interleaving. See the Task 5 fix report.
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

            $next = $current->replaced_by_id === null
                ? null
                : RefreshToken::query()->whereKey($current->replaced_by_id)->lockForUpdate()->first();

            if ($next === null) {
                break;
            }

            $current = $next;
        }

        return $revoked;
    }
}
