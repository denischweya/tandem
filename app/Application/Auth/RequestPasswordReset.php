<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Users\Email;
use App\Infrastructure\Auth\PasswordResetTokens;
use App\Infrastructure\Outbox\OutboxWriter;
use App\Models\User;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final readonly class RequestPasswordReset
{
    public function __construct(
        private PasswordResetTokens $tokens,
        private OutboxWriter $outbox,
    ) {}

    /**
     * Returns normally whether or not the address exists. The caller cannot distinguish the
     * two cases, which is the whole design: this endpoint is unauthenticated, so any
     * difference in its response is an account-existence oracle available to anyone.
     */
    public function execute(Email $email): void
    {
        $user = User::query()->where('email', $email->toString())->first();

        if ($user === null || $user->suspended_at !== null) {
            return;
        }

        DB::transaction(function () use ($user): void {
            $plaintext = $this->tokens->issue($user);

            // The token travels in the outbox payload, because the dispatcher builds the
            // email after the commit and the hash in password_reset_tokens cannot produce a
            // link. It is ENCRYPTED, not plaintext: OutboxDispatcher marks published_at and
            // never deletes the row, so a plaintext token here would persist indefinitely in
            // a table any database reader can see - which is what §142.7 forbids, and which
            // an earlier draft of this plan wrongly claimed was fine on the grounds that the
            // row is deleted on publish. It is not. Crypt uses APP_KEY, which is not in the
            // database, so a dump yields nothing usable.
            $this->outbox->write('User', $user->id, 'password.reset_requested', [
                'user_id' => $user->id,
                'token' => Crypt::encryptString($plaintext),
            ]);
        });
    }
}
