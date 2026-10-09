<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Application\Auth\Session;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin Session
 */
final class SessionResource extends JsonResource
{
    /**
     * Both expiries are returned because both clients need them: mobile schedules a refresh
     * ahead of the access token's expiry rather than waiting for a 401 on a user action, and
     * the web BFF sets each cookie's Max-Age from them.
     *
     * Read through $this, not through a local copy of $this->resource, for the same reason
     * UserResource does: JsonResource::$resource is declared @var mixed, and level 8 does not
     * check property access on mixed. Going through $this is what makes @mixin Session above
     * load-bearing, so renaming a Session property is caught by `make stan` instead of at
     * runtime.
     *
     * The (string) casts on the two formatted expiries are for Scramble, not PHPStan:
     * DateTimeImmutable::format() has no declared return type in PHP's own reflection
     * metadata (verified: ReflectionMethod::hasReturnType() is false for it), so
     * `make openapi`'s --fail-on-unknown export fails with SE001 "UnknownType is not
     * allowed" without them. PHPStan already infers `string` correctly via its own stubs,
     * so this is not a cast added to silence a PHPStan error - the method truly always
     * returns string; only reflection-based tooling needs the hint restated explicitly.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'access_token' => $this->accessToken,
            'access_token_expires_at' => (string) $this->accessTokenExpiresAt->format(DATE_RFC3339_EXTENDED),
            'refresh_token' => $this->refreshToken,
            'refresh_token_expires_at' => (string) $this->refreshTokenExpiresAt->format(DATE_RFC3339_EXTENDED),
            'user' => UserResource::make($this->user),
        ];
    }
}
