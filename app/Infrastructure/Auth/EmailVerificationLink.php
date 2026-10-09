<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Shared\Clock;
use App\Models\User;
use Illuminate\Support\Facades\URL;

final readonly class EmailVerificationLink
{
    private const TTL_HOURS = 24;

    public function __construct(private Clock $clock) {}

    /**
     * Returns a link to the WEB app, carrying the query string of a signed API URL.
     *
     * The signature covers the API route's absolute URL, so the web page can relay the query
     * verbatim to POST /api/v1/auth/verify-email and the HMAC still validates there. The hash
     * is sha1 of the current email, which is what invalidates an old link after an email
     * change - the id alone would not.
     */
    public function for(User $user): string
    {
        $signed = URL::temporarySignedRoute(
            'auth.verify-email',
            $this->clock->now()->modify('+'.self::TTL_HOURS.' hours'),
            ['id' => $user->id, 'hash' => sha1($user->email)],
        );

        $query = (string) parse_url($signed, PHP_URL_QUERY);

        return rtrim((string) config('app.web_url'), '/').'/verify-email?'.$query;
    }
}
