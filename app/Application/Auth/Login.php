<?php

declare(strict_types=1);

namespace App\Application\Auth;

use App\Domain\Shared\Clock;
use App\Domain\Users\Email;
use App\Infrastructure\Auth\RefreshTokenIssuer;
use App\Infrastructure\Auth\TokenIssuer;
use App\Models\Device;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

final readonly class Login
{
    /**
     * A valid bcrypt hash of a value no user has. Checked when no user matches, so that the
     * unknown-email path costs the same as the wrong-password path. Without it, response time
     * discloses whether an address is registered - the timing half of the oracle the
     * identical-response test covers.
     */
    private const DUMMY_HASH = '$2y$12$usesomesillystringfore7hnbRJHxXVLeakoG8K30oukPsA.ztMG';

    public function __construct(
        private TokenIssuer $tokenIssuer,
        private RefreshTokenIssuer $refreshTokenIssuer,
        private Clock $clock,
    ) {}

    public function execute(Email $email, string $password, ?DeviceDescriptor $descriptor): Session
    {
        $user = User::query()->where('email', $email->toString())->first();

        if ($user === null) {
            Hash::check($password, self::DUMMY_HASH);

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
