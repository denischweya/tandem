<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Shared\Clock;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class VerifyEmail
{
    public function __construct(private Clock $clock) {}

    /**
     * Resolves the user a signed verification payload names, or refuses.
     *
     * This lookup lives in the use case and not in VerifyEmailController because
     * tests/Architecture/DomainPurityTest.php forbids App\Http\Controllers from using
     * App\Models (or Eloquent, or App\Infrastructure) at all: a controller cannot load the
     * record, so the lookup and the check that authorizes it belong together here. The
     * controller holds the returned instance without ever naming the class.
     *
     * One answer for "no such user" and "hash does not match": both mean the link is not
     * valid, and a caller who can tell them apart learns which ids exist.
     *
     * The reason is NOT the one this docblock used to give. It claimed the signature "covers
     * the id, so anyone holding one valid link can mint queries for arbitrary ids", which is
     * backwards: UrlGenerator::hasCorrectSignature() HMACs the full query string with only
     * `signature` removed, so changing `id` invalidates the link. Measured - taking a valid
     * link and substituting another user's id and hash returns 403, while the original
     * returns 200. The stated hazard does not exist.
     *
     * The behaviour is still right, for a narrower reason that survives: the holder of a
     * valid link can distinguish the two cases FOR THEIR OWN ID - which tells them nothing -
     * and anyone with the APP_KEY can mint links for any id anyway, at which point an oracle
     * is the least of it. One answer costs nothing and removes the question.
     *
     * @throws AuthorizationException
     */
    public function forSignedPayload(string $id, string $hash): User
    {
        $user = User::query()->find($id);

        if ($user === null || ! hash_equals(sha1($user->email), $hash)) {
            throw new AuthorizationException;
        }

        return $user;
    }

    /** Idempotent: a second valid link for an already verified user is a success, not an error. */
    public function execute(User $user): void
    {
        if ($user->email_verified_at !== null) {
            return;
        }

        $user->forceFill(['email_verified_at' => $this->clock->now()])->save();
    }
}
