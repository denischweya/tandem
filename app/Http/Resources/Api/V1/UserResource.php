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
     * Read through $this, not through a local copy of $this->resource, because that is the
     * only form PHPStan actually checks here. JsonResource::$resource is declared @var mixed,
     * and @mixin is a fallback for members NOT found on the class - `resource` IS found, so
     * `$user = $this->resource` is mixed, and level 8 does not check property access on mixed
     * (that is level 9's checkExplicitMixed). Measured with a probe: reading a nonexistent
     * property off the local raised nothing, while reading one off $this raised
     * property.notFound. Going through $this is what makes @mixin User above load-bearing, so
     * renaming a model property is caught by `make stan` instead of at runtime.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'email_verified' => $this->email_verified_at !== null,
            'display_name' => $this->profile->display_name,
            'timezone' => $this->profile->timezone,
            'locale' => $this->profile->locale,
        ];
    }
}
