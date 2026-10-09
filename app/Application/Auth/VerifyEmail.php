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
     * valid. Distinguishing them would make this endpoint an account-existence oracle, and
     * the signature does not prevent that - it covers the id, so anyone holding one valid
     * link can mint queries for arbitrary ids.
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
