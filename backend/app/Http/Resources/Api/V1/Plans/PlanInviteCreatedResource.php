<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Plans;

use App\Application\Plans\IssuedInvite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin IssuedInvite */
final class PlanInviteCreatedResource extends JsonResource
{
    /**
     * The 201 from spec §10.4 only. `token` is the plaintext, returned exactly once, because
     * that is the only moment it exists outside the invitation email - PasswordResetTokens'
     * own docblock says the same thing about reset tokens. `plan_invites` stores only the
     * token's hash, so a second chance to read it does not exist.
     *
     * IssuedInvite here is App\Application\Plans\IssuedInvite - eight typed scalars, never an
     * Eloquent model - so there is no property on this mixin that could leak token_hash even
     * by accident.
     *
     * invited_by_name is published NULLABLE even though at issuance the inviter is the
     * authenticated actor and the name cannot in fact be missing (user_profiles.user_id is
     * UNIQUE NOT NULL). The published type is pinned by the contract section's
     * PlanInviteCreatedResourceSchema - `invited_by_name: z.string().nullable()` - and
     * WireEquivalent's proofs are symmetric with PlanInviteResourceSchema, so a non-nullable
     * property here would fail that proof. The weaker type is the deliberate choice.
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
            'token' => $this->token,
        ];
    }
}
