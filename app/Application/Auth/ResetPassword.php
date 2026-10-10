<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Auth\PasswordResetState;
use App\Domain\Shared\Clock;
use App\Domain\Users\Email;
use App\Infrastructure\Auth\AccessTokenRevoker;
use App\Infrastructure\Auth\PasswordResetTokens;
use App\Models\PasswordResetToken;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class ResetPassword
{
    public function __construct(
        private PasswordResetTokens $tokens,
        private AccessTokenRevoker $accessTokens,
        private Clock $clock,
    ) {}

    /**
     * Every throw below happens before the first write in this closure - the lookup, the
     * owner check and the state check are all reads. DB::transaction() rolls back on any
     * Throwable, so a throw positioned after a write would undo it - which is exactly how
     * RefreshSession's chain revocation first shipped, and why that method returns null from
     * its closure and throws afterwards instead. There is no write to undo here, because none
     * has happened by the time any throw statement below can run.
     */
    public function execute(string $plainToken, Email $email, string $newPassword): void
    {
        DB::transaction(function () use ($plainToken, $email, $newPassword): void {
            $token = PasswordResetToken::query()
                ->where('token_hash', hash('sha256', $plainToken))
                ->lockForUpdate()
                ->first();

            if ($token === null) {
                throw new InvalidPasswordResetToken;
            }

            $user = $token->user;

            // The email must match the token's owner. Without this, the token alone is
            // authority to change any account's password, and tokens travel through email -
            // the least trustworthy channel in the system.
            if ($user === null || $user->email !== $email->toString()) {
                throw new InvalidPasswordResetToken;
            }

            if ($this->tokens->stateOf($token) !== PasswordResetState::Valid) {
                throw new InvalidPasswordResetToken;
            }

            $this->tokens->claim($token);

            $user->password_hash = Hash::make($newPassword);
            $user->save();

            // Every session dies. A reset is how someone recovers an account they believe is
            // compromised; leaving the attacker's refresh token or access token live would
            // make the reset cosmetic. Spec §9.1.1 established this same scope for a detected
            // refresh-chain replay.
            $user->refreshTokens()->whereNull('revoked_at')->update(['revoked_at' => $this->clock->now()]);
            $this->accessTokens->revokeAllFor($user->id);
        });
    }
}
