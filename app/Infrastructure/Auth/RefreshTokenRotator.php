<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Auth\RefreshTokenState;
use App\Domain\Shared\Clock;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\Log;

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
     * transactions interleaving. See the method docblock on RefreshSession::execute() for the
     * rollback hazard that shapes this method's caller.
     *
     * THE WALK IS BOUNDED, AND THE BOUND IS DERIVED RATHER THAN CHOSEN. It used to be a
     * literal 1000, described as costing nothing - which was wrong twice over. A chain cannot
     * cycle (replaced_by_id always points at a row created later), so the constant was not
     * guarding against anything that happens; and spec §9.1.1 deliberately gives Cycle 1 no
     * absolute session lifetime, so a rotating session is immortal. At a 15-minute access TTL
     * an active client produces 20-96 rotations a day, which passes 1000 links in weeks to
     * months - after which a replay revoked the first 1000 rows and left the LIVE TAIL ACTIVE,
     * silently, which is precisely the outcome this method exists to prevent. A functional
     * limit was wearing a corruption guard's clothes.
     *
     * chainBoundFor() instead: a chain is a set of distinct rows all belonging to one user
     * (rotate() issues each successor for the same user), so it can never be longer than that
     * user's refresh_tokens row count. One extra indexed COUNT, and no legitimate chain of any
     * length can be truncated. A corrupted chain still terminates, and exhausting the bound is
     * now evidence of corruption rather than evidence of an old account - so it is logged
     * rather than swallowed.
     *
     * @return int how many rows were revoked - read by RefreshSession for its log line, which
     *             is what makes "the chain was revoked" a claim with a number attached
     */
    public function revokeChainFrom(RefreshToken $token): int
    {
        $revoked = 0;
        $now = $this->clock->now();
        $current = $token;
        $remainingHops = $this->chainBoundFor($token);

        while (true) {
            if ($current->revoked_at === null) {
                $current->forceFill(['revoked_at' => $now])->save();
                $revoked++;
            }

            $next = $current->replaced_by_id === null
                ? null
                : RefreshToken::query()->whereKey($current->replaced_by_id)->lockForUpdate()->first();

            if ($next === null) {
                return $revoked;
            }

            if (--$remainingHops <= 0) {
                // Logged, not thrown. A throw here would roll back the revocations this loop
                // has already written - the exact defect RefreshSession::execute()'s docblock
                // records, where a throw inside the transaction undid the chain revocation it
                // had just performed and left the thief's successor live. The caller still
                // answers 401 either way, so a throw would buy noise rather than safety; what
                // was missing was any evidence at all.
                Log::warning('security.refresh_token.chain_walk_exhausted', [
                    'event' => 'security.refresh_token.chain_walk_exhausted',
                    'user_id' => $token->user_id,
                    'stopped_at_refresh_token_id' => $current->id,
                    'revoked' => $revoked,
                    // No token material: these are row ids, and the plaintext exists only in
                    // the client's hands. token_hash is omitted too - it is the lookup key for
                    // the credential, and a log is not where it belongs.
                ]);

                return $revoked;
            }

            $current = $next;
        }
    }

    /**
     * The longest a chain could possibly be, plus one hop's slack.
     *
     * Every row in a chain belongs to the same user and each is visited at most once in a
     * well-formed chain, so the user's own row count is an upper bound that no real chain can
     * reach. The +1 keeps the comparison off the boundary: a chain of exactly N rows takes
     * N-1 hops, so only a chain that revisits a row can exhaust this.
     */
    private function chainBoundFor(RefreshToken $token): int
    {
        return RefreshToken::query()->where('user_id', $token->user_id)->count() + 1;
    }
}
