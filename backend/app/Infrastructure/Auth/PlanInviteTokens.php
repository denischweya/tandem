<?php

declare(strict_types=1);

namespace App\Infrastructure\Auth;

use App\Domain\Plans\InviteState;
use App\Domain\Plans\MemberRole;
use App\Domain\Shared\Clock;
use App\Domain\Users\Email;
use App\Models\Plan;
use App\Models\PlanInvite;
use App\Models\User;

final readonly class PlanInviteTokens
{
    /**
     * 14 days. Blueprint §131 requires an invite link to be expirable and gives no number, and
     * both TTLs already in this codebase are wrong for this credential by an order of magnitude,
     * in opposite directions: PasswordResetTokens::TTL_MINUTES is 60, which is for someone
     * sitting at a password form, and EmailVerificationLink::TTL_HOURS is 24, which makes a
     * Friday-evening invite dead by Monday morning. A Plan sits days to weeks out, so 14 days
     * bounds a leaked link to roughly one planning cycle and still survives a holiday. No expiry
     * at all is what blueprint §131 forbids.
     */
    public const TTL_DAYS = 14;

    public function __construct(private Clock $clock) {}

    /**
     * Mints the credential and writes the row. No transaction of its own, deliberately: it must
     * join the caller's, so the invite row, the activity row and the outbox event commit or roll
     * back together - the same rule OutboxWriter::write() states for itself.
     *
     * Issuing does NOT supersede outstanding invites, which is the opposite of
     * PasswordResetTokens::issue(). A reset supersedes because requesting a second reset is a
     * statement that the first email is not the one being acted on - one account, one live reset.
     * An invite names a DIFFERENT PERSON each time, so superseding would mean inviting Sarah
     * kills the link already sent to James. Two live invites for the same address are harmless:
     * both are single use, the first accepted wins, and the other stays pending until it expires
     * or is revoked.
     *
     * A consequence worth stating because it closes off a feature request: a repeated invite
     * CANNOT be made idempotent. Only the hash is stored, so there is no way to return the
     * plaintext of an existing invite, and "return the outstanding invite instead of minting a
     * new one" is not implementable.
     */
    public function issue(Plan $plan, User $invitedBy, ?Email $email, MemberRole $role): MintedInvite
    {
        // bin2hex(random_bytes(32)): 64 lowercase hex characters, 256 bits, byte for byte the
        // shape of both existing credentials of this kind (RefreshTokenIssuer,
        // PasswordResetTokens::issue()). Blueprint §131's "random, unguessable" is satisfied by
        // 256 bits and by nothing weaker.
        $plaintext = bin2hex(random_bytes(32));

        $invite = new PlanInvite;

        // forceFill, because nothing on this model is mass-assignable - and every field is
        // assigned here EXPLICITLY rather than left to a column default. That is not style:
        // fill() iterates only over the attributes supplied, so an omitted `role` is simply
        // absent from the INSERT and Postgres applies DEFAULT 'member' silently. The explicit
        // assignment is what makes the role the caller asked for the role that is stored.
        $invite->forceFill([
            'plan_id' => $plan->id,
            'invited_by' => $invitedBy->id,
            'email' => $email?->toString(),
            'token_hash' => hash('sha256', $plaintext),
            'role' => $role->value,
            'expires_at' => $this->clock->now()->modify('+'.self::TTL_DAYS.' days'),
        ])->save();

        return new MintedInvite($invite, $plaintext, $invitedBy->profile->display_name);
    }

    /**
     * Accepted is tested BEFORE Expired, and Revoked before Expired, and both orderings are
     * design decisions rather than implementation details: an invite that was used and has since
     * expired must report reuse, and one that was revoked and has since expired must report
     * revocation. This mirrors PasswordResetTokens::stateOf() (used before expired) and
     * RefreshTokenRotator::stateOf() (replaced_by_id before revoked_at) - and the latter's
     * ordering is why replay detection survived the Revoked => Reject change. Both orderings are
     * asserted by a test rather than left to be read off this method.
     */
    public function stateOf(PlanInvite $invite): InviteState
    {
        if ($invite->accepted_at !== null) {
            return InviteState::Accepted;
        }

        if ($invite->revoked_at !== null) {
            return InviteState::Revoked;
        }

        if ($invite->expires_at->toDateTimeImmutable() <= $this->clock->now()) {
            return InviteState::Expired;
        }

        return InviteState::Valid;
    }

    /**
     * Stamps the row single-use. PasswordResetTokens::claim() is this method with one fewer
     * column - and the extra column is the point: accepted_by records who actually joined, which
     * is the whole of §10.6's trail, because an invite with a non-null email may be accepted by
     * any authenticated user who holds the token.
     *
     * forceFill, because nothing on PlanInvite is mass-assignable. No transaction of its own: it
     * must join the caller's, so the claim, the membership row and the activity row commit or
     * roll back together.
     */
    public function claim(PlanInvite $invite, User $acceptedBy): void
    {
        $invite->forceFill([
            'accepted_at' => $this->clock->now(),
            'accepted_by' => $acceptedBy->id,
        ])->save();
    }
}
