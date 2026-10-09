<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Auth\RotationOutcome;
use App\Domain\Auth\RotationPolicy;
use App\Infrastructure\Auth\RefreshTokenRotator;
use App\Infrastructure\Auth\TokenIssuer;
use App\Models\RefreshToken;
use Illuminate\Support\Facades\DB;

final readonly class RefreshSession
{
    public function __construct(
        private RefreshTokenRotator $rotator,
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

                // Returned, never thrown: a throw here rolls this transaction back and with
                // it every row the line above just revoked. See the method docblock.
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
