<?php

declare(strict_types=1);

namespace App\Domain\Plans;

/**
 * Pure: no database, no framework, no clock. Expiry has already been resolved into
 * InviteState by the caller (PlanInviteTokens::stateOf()), which is what keeps this decidable
 * from an enum alone and therefore unit-testable with no fixtures.
 *
 * Deliberately does not decide: token lookup (Infrastructure); whether invite.plan_id matches
 * the {plan} path parameter, which must be indistinguishable from an unknown token rather than
 * a policy outcome (Application); whether the Plan is archived (Application - kept out of
 * InviteState so this stays decidable from the invite row alone).
 *
 * Email binding: deliberately none. An invite with a non-null email may be accepted by any
 * authenticated user who holds the token - the accepting user's id is recorded in
 * accepted_by, but the submitted session need not match the invited address. A reset-password
 * token binds its email because that token is authority over an account; an invite token's
 * blast radius is only "someone joins a Plan", which is what the person who sent it chose to
 * expose. Binding it would also lock out the common case of being invited at one address and
 * signing in with another.
 */
final class InvitePolicy
{
    public static function decide(InviteState $state): InviteOutcome
    {
        // match, not a chain of ifs: adding a case to InviteState without handling it here
        // throws UnhandledMatchError rather than silently falling through to a default, which
        // is what makes the exhaustiveness test below meaningful - RotationPolicy's pattern,
        // for exactly RotationPolicy's reason.
        return match ($state) {
            // A live, unexpired, unrevoked, unaccepted invite is exactly what it looks like.
            InviteState::Valid => InviteOutcome::Accept,

            // Invite links must be expirable.
            InviteState::Expired => InviteOutcome::Reject,

            // Invite links must be revocable.
            InviteState::Revoked => InviteOutcome::Reject,

            // Single use: an invite already claimed cannot be claimed again. WHICH of Accepted
            // or Expired a given row resolves to when both conditions hold is the resolver's
            // obligation (PlanInviteTokens::stateOf()), not this method's - this method is
            // handed one InviteState and decides from it alone.
            InviteState::Accepted => InviteOutcome::Reject,
        };
    }
}
