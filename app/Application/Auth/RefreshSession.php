<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Auth\RotationOutcome;
use App\Domain\Auth\RotationPolicy;
use App\Infrastructure\Auth\AccessTokenRevoker;
use App\Infrastructure\Auth\RefreshTokenRotator;
use App\Infrastructure\Auth\TokenIssuer;
use App\Models\RefreshToken;
use Illuminate\Support\Facades\DB;

final readonly class RefreshSession
{
    public function __construct(
        private RefreshTokenRotator $rotator,
        private AccessTokenRevoker $accessTokenRevoker,
        private TokenIssuer $tokenIssuer,
    ) {}

    /**
     * The transaction returns null for every failure and InvalidRefreshToken is thrown after
     * it has committed, rather than from inside the closure. This is not a style choice:
     * DB::transaction() rolls back on any Throwable, so throwing from inside the RevokeChain
     * branch undid the chain revocation it had just performed - the replay was still refused
     * with a 401, and the thief's successor token was left live and working. Measured, not
     * reasoned about: calling revokeChainFrom() directly outside a transaction revoked the
     * live tail of a 3-token chain, while the same call through this endpoint left it with
     * revoked_at NULL. The "revokes the entire chain when a rotated token is replayed" test
     * is what caught it, and is the only assertion in the suite that can - a test checking
     * only the 401 passes against the rolled-back version.
     *
     * @throws InvalidRefreshToken for an unknown, expired, revoked or replayed token - one
     *                             exception for all four, see the class docblock there
     */
    public function execute(string $plainRefreshToken): Session
    {
        $session = DB::transaction(function () use ($plainRefreshToken): ?Session {
            // lockForUpdate, because two concurrent refreshes with the same token are exactly
            // the race this design must resolve deterministically: one rotates, the other
            // sees Rotated and revokes the chain. Without the lock both could rotate, and the
            // replay signal would be lost.
            $token = RefreshToken::query()
                ->where('token_hash', hash('sha256', $plainRefreshToken))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                return null;
            }

            $outcome = RotationPolicy::decide($this->rotator->stateOf($token));

            if ($outcome === RotationOutcome::RevokeChain) {
                $this->rotator->revokeChainFrom($token);

                // Spec §9.1.1: the refresh lineage alone is not the whole credential. Every
                // access token already minted from this chain keeps working until it expires -
                // up to TokenIssuer::TTL_MINUTES, so up to fifteen minutes of full API access
                // for whoever triggered the detection. Chain revocation already signs the
                // legitimate user out, because that is the deliberate cost of the mechanism;
                // leaving the thief a quarter of an hour alongside it is not a trade anyone
                // chose. Sanctum tokens carry no device association, so the scope is every
                // access token the user holds - which §9.1.1 records as the right scope
                // regardless, a detected replay meaning a credential of theirs is in someone
                // else's hands.
                //
                // Keyed on $token->user_id, not on a resolved User: the branch below is where
                // a soft-deleted account turns into a null, and this delete must not be
                // skipped for one.
                $this->accessTokenRevoker->revokeAllFor($token->user_id);

                // Returned, never thrown: a throw here rolls this transaction back and with it
                // both the rows just revoked and the tokens just deleted. See the method
                // docblock - that is the defect this shape exists to prevent, so the throw
                // stays after the commit.
                return null;
            }

            if ($outcome === RotationOutcome::Reject) {
                return null;
            }

            // first() on the relation rather than $token->user, so the null a soft-deleted
            // user produces is a value this code has to handle instead of one the type system
            // hides: the relation respects the SoftDeletes global scope, so a deleted
            // account's token resolves to nothing here rather than minting a fresh session
            // for it.
            $user = $token->user()->first();

            if ($user === null) {
                return null;
            }

            $successor = $this->rotator->rotate($token, $user);
            $access = $this->tokenIssuer->issue($user);

            return new Session(
                $access->plaintext,
                $access->expiresAt,
                $successor->plaintext,
                $successor->model->expires_at->toDateTimeImmutable(),
                $user,
            );
        });

        if (! $session instanceof Session) {
            throw new InvalidRefreshToken;
        }

        return $session;
    }
}
