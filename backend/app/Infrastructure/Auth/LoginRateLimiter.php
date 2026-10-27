<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Spec §8.2 (amended): login's two limits count failed sign-in attempts, not requests -
 * unlike every other throttled row, which counts requests. Counting requests here would let
 * a multi-device user, a reinstall, or a client retrying through a timeout lock themselves
 * out with nothing but legitimate traffic; counting failures protects the account without
 * penalising the owner for using it. That is also why this cannot live in route middleware:
 * `throttle:` decides before the controller runs, when the outcome of this attempt is not
 * yet known. Login::execute() calls ensureNotExceeded() before attempting the credentials,
 * then hit() only on failure or clear() only on success, mirroring Illuminate\Foundation\
 * Auth\ThrottlesLogins - the one difference being two independent keys (IP and email) in
 * place of ThrottlesLogins' single combined one, because §8.2 specifies both limits
 * separately and both must keep working if only one key is ever cleared by a mistake.
 *
 * Both limits are checked and both are hit/cleared together, deliberately: a request that
 * fails is a failure from both the IP's and the email's perspective, and a request that
 * succeeds is evidence the (IP, email) pair was legitimate from both perspectives too.
 */
final readonly class LoginRateLimiter
{
    private const IP_MAX_ATTEMPTS = 10;

    private const EMAIL_MAX_ATTEMPTS = 5;

    private const DECAY_SECONDS = 15 * 60;

    /** @throws ThrottleRequestsException */
    public function ensureNotExceeded(string $ip, string $email): void
    {
        $this->ensureKeyNotExceeded($this->ipKey($ip), self::IP_MAX_ATTEMPTS);
        $this->ensureKeyNotExceeded($this->emailKey($email), self::EMAIL_MAX_ATTEMPTS);
    }

    public function hit(string $ip, string $email): void
    {
        RateLimiter::hit($this->ipKey($ip), self::DECAY_SECONDS);
        RateLimiter::hit($this->emailKey($email), self::DECAY_SECONDS);
    }

    public function clear(string $ip, string $email): void
    {
        RateLimiter::clear($this->ipKey($ip));
        RateLimiter::clear($this->emailKey($email));
    }

    private function ensureKeyNotExceeded(string $key, int $maxAttempts): void
    {
        if (! RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            return;
        }

        // Same exception class and the same JSON shape ThrottleRequests middleware would
        // have thrown, so a client cannot tell this 429 came from application code rather
        // than middleware.
        throw new ThrottleRequestsException('Too Many Attempts.', null, [
            'Retry-After' => RateLimiter::availableIn($key),
        ]);
    }

    private function ipKey(string $ip): string
    {
        return 'auth-login:ip:'.$ip;
    }

    private function emailKey(string $email): string
    {
        return 'auth-login:email:'.$email;
    }
}
