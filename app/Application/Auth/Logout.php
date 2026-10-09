<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Shared\Clock;
use App\Models\PersonalAccessToken;
use App\Models\RefreshToken;
use App\Models\User;
use Illuminate\Support\Facades\DB;

final readonly class Logout
{
    public function __construct(private Clock $clock) {}

    /**
     * Scoped to the caller's own tokens. Without the user_id predicate, logout would revoke
     * any refresh token whose plaintext the caller presents - a remote session-kill primitive
     * for anyone who captures someone else's token. A token that is not theirs is silently
     * ignored rather than reported, for the same reason InvalidRefreshToken has one message.
     */
    public function execute(User $user, string $plainRefreshToken): void
    {
        DB::transaction(function () use ($user, $plainRefreshToken): void {
            RefreshToken::query()
                ->where('user_id', $user->id)
                ->where('token_hash', hash('sha256', $plainRefreshToken))
                ->whereNull('revoked_at')
                ->update(['revoked_at' => $this->clock->now()]);

            // The access token the caller authenticated with. Deleting rather than expiring:
            // personal_access_tokens has no revoked_at, and a deleted row cannot authenticate.
            //
            // A real instanceof rather than ?->delete() or an annotation: HasApiTokens
            // declares currentAccessToken() as returning TToken, which resolves to a
            // non-nullable PersonalAccessToken, so a nullsafe call there is rejected at level
            // 8 as never-null while the method genuinely returns null for an unauthenticated
            // caller - and can return a Sanctum TransientToken (session-guard SPA auth, ADR
            // 0004's web BFF route), which has no row to delete at all. Narrowing to this
            // project's own model is the one form that is both true and checked.
            $accessToken = $user->currentAccessToken();

            if ($accessToken instanceof PersonalAccessToken) {
                $accessToken->delete();
            }
        });
    }
}
