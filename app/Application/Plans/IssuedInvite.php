<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\MemberRole;
use App\Models\PlanInvite;
use Illuminate\Support\Carbon;

/**
 * The 201 from spec §10.4, published exactly once. A separate class from PlanInviteView rather
 * than that class plus an optional token: spec §6.1 and tandem-shared's WireShape move
 * optionality into the value type, and both classes are final so neither could extend the
 * other even if that were the right shape.
 *
 * IN App\Application\Plans, NOT App\Infrastructure\Auth. This is what a future invite-issuing
 * use case will return and what PlanInviteCreatedResource renders - it is not auth and it is
 * not infrastructure. The shipped codebase already draws this line for the same subsystem:
 * App\Infrastructure\Auth\IssuedAccessToken and ...\IssuedRefreshToken are themselves `Issued*`
 * value objects kept inside Infrastructure\Auth, and a grep over app/ and tests/ finds no
 * reference to either outside that one directory - they never cross a layer boundary. What
 * DOES cross, for the same subsystem, is App\Application\Auth\Session, which
 * RefreshTokenIssuer::issue(): IssuedRefreshToken is mapped into before Http ever sees it. The
 * invite-issuing use case is expected to follow the same pattern when it is written.
 *
 * EIGHT TYPED SCALARS and no PlanInvite property, for PlanInviteView's reason plus one more:
 * the model carries token_hash, and a JsonResource that could reach it is one property access
 * away from publishing a credential. With scalars, writing 'token_hash' => $this->invite-
 * >token_hash is impossible rather than merely unwritten.
 *
 * invitedByName is ?string even though this response can never in practice carry null - at
 * issuance the inviter IS the authenticated actor, and user_profiles.user_id is UNIQUE NOT
 * NULL. The published type is pinned by the contract section's own
 * PlanInviteCreatedResourceSchema (`invited_by_name: z.string().nullable()`), and
 * WireEquivalent's proofs are symmetric with PlanInviteResourceSchema, so a non-nullable
 * property here would fail that proof. The weaker type is the deliberate choice.
 */
final readonly class IssuedInvite
{
    public function __construct(
        public string $id,
        public ?string $email,
        public MemberRole $role,
        public Carbon $expiresAt,
        public ?Carbon $acceptedAt,
        public ?Carbon $revokedAt,
        public ?string $invitedByName,
        public string $token,
    ) {}

    /**
     * $token is the plaintext, handed in by the caller - never read from the model, which
     * stores only its hash. This is the one moment the plaintext exists outside the
     * invitation email.
     */
    public static function fromInvite(PlanInvite $invite, ?string $invitedByName, string $token): self
    {
        return new self(
            id: $invite->id,
            email: $invite->email,
            // from(), not tryFrom() - matching PlanInviteView::fromInvite()'s reasoning for the
            // same column: a corrupt role is a loud ValueError, not a silently wrong value.
            role: MemberRole::from($invite->role),
            expiresAt: $invite->expires_at,
            acceptedAt: $invite->accepted_at,
            revokedAt: $invite->revoked_at,
            invitedByName: $invitedByName,
            token: $token,
        );
    }
}
