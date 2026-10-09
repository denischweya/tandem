<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin User
 */
final class UserResource extends JsonResource
{
    /**
     * `email_verified` as a boolean, not `email_verified_at` as a timestamp: when the address
     * was confirmed is of no use to any client and is one more piece of personal history on
     * the wire. Every other timestamp this API returns is one a client renders.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        // No inline @var narrowing this: the class-level @mixin User above already types it,
        // and this project does not use inline @var to override an inferred type. Verified
        // both ways - PHPStan level 8 is clean and openapi.json is byte-identical either way.
        $user = $this->resource;

        return [
            'id' => $user->id,
            'email' => $user->email,
            'email_verified' => $user->email_verified_at !== null,
            'display_name' => $user->profile->display_name,
            'timezone' => $user->profile->timezone,
            'locale' => $user->profile->locale,
        ];
    }
}
