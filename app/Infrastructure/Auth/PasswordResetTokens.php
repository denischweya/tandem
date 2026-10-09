<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Auth\PasswordResetState;
use App\Domain\Shared\Clock;
use App\Models\PasswordResetToken;
use App\Models\User;

final readonly class PasswordResetTokens
{
    /**
     * 60 minutes. Long enough to find the email in a spam folder, short enough that a token
     * sitting in a mailbox someone else later gains access to is usually dead.
     */
    public const TTL_MINUTES = 60;

    public function __construct(private Clock $clock) {}

    /** @return string the plaintext token - the only moment it exists outside the email */
    public function issue(User $user): string
    {
        $plaintext = bin2hex(random_bytes(32));

        // Any token already outstanding for this user is invalidated. Requesting a second
        // reset is a statement that the first email is not the one being acted on.
        PasswordResetToken::query()
            ->where('user_id', $user->id)
            ->whereNull('used_at')
            ->update(['used_at' => $this->clock->now()]);

        PasswordResetToken::create([
            'user_id' => $user->id,
            'token_hash' => hash('sha256', $plaintext),
            'expires_at' => $this->clock->now()->modify('+'.self::TTL_MINUTES.' minutes'),
        ]);

        return $plaintext;
    }

    public function stateOf(PasswordResetToken $token): PasswordResetState
    {
        if ($token->used_at !== null) {
            return PasswordResetState::Used;
        }

        if ($token->expires_at->toDateTimeImmutable() <= $this->clock->now()) {
            return PasswordResetState::Expired;
        }

        return PasswordResetState::Valid;
    }

    public function claim(PasswordResetToken $token): void
    {
        $token->forceFill(['used_at' => $this->clock->now()])->save();
    }
}
