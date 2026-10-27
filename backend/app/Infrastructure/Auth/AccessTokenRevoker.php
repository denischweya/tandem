<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Models\PersonalAccessToken;
use App\Models\User;

/**
 * The counterpart to TokenIssuer: that class mints access tokens, this one destroys them.
 * Separate from TokenIssuer because nothing about revocation needs the Clock or the TTL, and
 * separate from Logout because the scopes differ - Logout deletes the one token its caller
 * authenticated with, this deletes every token a user holds.
 */
final readonly class AccessTokenRevoker
{
    /**
     * Deleted rather than expired: personal_access_tokens has no revoked_at column, and a
     * deleted row cannot authenticate. Same reasoning as Logout's single-token delete.
     *
     * Keyed on the user id rather than on a loaded User, deliberately. The caller reaching
     * for this has a refresh-token row, not necessarily a resolvable account: User's
     * SoftDeletes global scope resolves a deleted account's relation to null, which would
     * silently skip the delete. A detected replay is the last moment to be lenient about
     * that.
     *
     * getMorphClass() rather than the literal User::class, so this keeps working if a morph
     * map is ever enforced - at which point tokenable_type stops being the FQCN and a
     * hardcoded one would match nothing while still reporting success.
     *
     * void, not a count. Eloquent\Builder::delete() is declared mixed, so an int return type
     * here would be a claim PHPStan cannot check at level 8, narrowable only with a cast or an
     * is_int() dance - and no caller wants it. (RefreshTokenRotator::revokeChainFrom() does
     * return a count, and that is not an inconsistency: RefreshSession reads it for its
     * replay log line, where "the chain was revoked" is worth a number. Nothing wants one
     * here, because the answer is always "all of them".)
     */
    public function revokeAllFor(string $userId): void
    {
        PersonalAccessToken::query()
            ->where('tokenable_type', (new User)->getMorphClass())
            ->where('tokenable_id', $userId)
            ->delete();
    }
}
