<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Shared\Clock;
use App\Domain\Users\Email;
use App\Infrastructure\Auth\DummyPasswordHash;
use App\Infrastructure\Auth\LoginRateLimiter;
use App\Infrastructure\Auth\RefreshTokenIssuer;
use App\Infrastructure\Auth\TokenIssuer;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class Login
{
    public function __construct(
        private TokenIssuer $tokenIssuer,
        private RefreshTokenIssuer $refreshTokenIssuer,
        private LoginRateLimiter $rateLimiter,
        private DummyPasswordHash $dummyPasswordHash,
        private Clock $clock,
    ) {}

    /**
     * $ip identifies the caller for the per-IP half of spec §8.2's login throttle; it is a
     * plain string, not a Request, so this use case stays free of the HTTP layer.
     */
    public function execute(Email $email, string $password, ?DeviceDescriptor $descriptor, string $ip): Session
    {
        $emailString = $email->toString();

        // Checked before the attempt, against attempts already on record - not this
        // request's own outcome, which is not yet known. This is the reason the throttle
        // cannot live in route middleware any more: middleware decides before the
        // controller runs, and spec §8.2 now counts failures, not requests.
        $this->rateLimiter->ensureNotExceeded($ip, $emailString);

        try {
            $session = $this->attempt($emailString, $password, $descriptor);
        } catch (InvalidCredentials $e) {
            $this->rateLimiter->hit($ip, $emailString);

            throw $e;
        }

        // A successful sign-in is evidence the (ip, email) pair was legitimate, so it must
        // not spend any of the budget a later legitimate attempt will need - that is the
        // whole point of counting failures instead of requests.
        $this->rateLimiter->clear($ip, $emailString);

        return $session;
    }

    private function attempt(string $email, string $password, ?DeviceDescriptor $descriptor): Session
    {
        $user = User::query()->where('email', $email)->first();

        if ($user === null) {
            // Constant-time half of the sign-in-failure oracle: this keeps the unknown-email
            // path costing the same as the wrong-password path. The identical-response test
            // covers the other half, the body.
            Hash::check($password, $this->dummyPasswordHash->value());

            throw new InvalidCredentials;
        }

        if (! Hash::check($password, $user->password_hash)) {
            throw new InvalidCredentials;
        }

        if ($user->suspended_at !== null) {
            throw new InvalidCredentials;
        }

        return DB::transaction(function () use ($user, $descriptor): Session {
            $device = $descriptor === null ? null : $this->rememberDevice($user, $descriptor);

            $access = $this->tokenIssuer->issue($user);
            $refresh = $this->refreshTokenIssuer->issue($user, $device);

            return new Session(
                $access->plaintext,
                $access->expiresAt,
                $refresh->plaintext,
                // The model's cast gives a Carbon; the DTO's contract is DateTimeImmutable,
                // which Carbon satisfies through toDateTimeImmutable() without the DTO having
                // to know about Carbon at all.
                $refresh->model->expires_at->toDateTimeImmutable(),
                $user,
            );
        });
    }

    private function rememberDevice(User $user, DeviceDescriptor $descriptor): Device
    {
        // updateOrCreate, not create: UNIQUE(user_id, install_id) makes a second sign-in on
        // the same phone throw otherwise, and signing in twice on one device is the common
        // case rather than an edge case.
        return Device::query()->updateOrCreate(
            ['user_id' => $user->id, 'install_id' => $descriptor->installId],
            ['platform' => $descriptor->platform, 'last_seen_at' => $this->clock->now()],
        );
    }
}
