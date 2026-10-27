<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Plans;

use App\Application\Plans\PlanInviteView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanInviteView */
final class PlanInviteResource extends JsonResource
{
    /**
     * SEVEN fields, and neither `token` nor `token_hash` is one of them. A token is shown once,
     * at issuance, in PlanInviteCreatedResource (§6.1, §10.1); listing the hash would be
     * pointless and listing the plaintext is impossible. With PlanInviteView being seven typed
     * scalars, `token_hash` is not merely unwritten here - it is unreachable, because the model
     * stops at the repository.
     *
     * Read through $this, never through a local copy of $this->resource: `JsonResource::$resource`
     * is declared as mixed, the class-level mixin above is a fallback only for members not found
     * on the class, and level 8 does not check property access on a mixed value - so going
     * through $this is what keeps the mixin load-bearing, turning a renamed property into a
     * `make stan` failure instead of a runtime one.
     *
     * The (string) casts on the three formatted timestamps are for Scramble, not PHPStan: PHP's
     * reflection carries no declared return type for DateTime::format(), which Carbon inherits,
     * so `make openapi`'s --fail-on-unknown export fails with SE001 "UnknownType is not
     * allowed" without them - the same measurement SessionResource's own docblock records.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'email' => $this->email,
            'role' => $this->role->value,
            /** @format date-time */
            'expires_at' => (string) $this->expiresAt->format(DATE_RFC3339_EXTENDED),
            /** @format date-time */
            'accepted_at' => $this->acceptedAt === null ? null : (string) $this->acceptedAt->format(DATE_RFC3339_EXTENDED),
            /** @format date-time */
            'revoked_at' => $this->revokedAt === null ? null : (string) $this->revokedAt->format(DATE_RFC3339_EXTENDED),
            'invited_by_name' => $this->invitedByName,
        ];
    }
}
