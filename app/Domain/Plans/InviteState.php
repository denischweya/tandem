<?php

declare(strict_types=1);

namespace App\Domain\Plans;

/**
 * The four states one plan_invites row can resolve to, by the time InvitePolicy is asked to
 * decide anything. Unbacked: this is an in-memory decision type, resolved from the row by
 * Infrastructure (PlanInviteTokens::stateOf()), never persisted as a column value - the same
 * shape as RefreshTokenState for the same reason.
 *
 * Declaration order here carries no check-order meaning: InvitePolicy::decide() is one match
 * with one unconditional outcome per case, so there is no priority between cases for this file
 * to express. The check order that matters belongs to the resolver that produces one of these
 * four values from a row that may satisfy more than one condition at once (an invite that was
 * accepted and has since expired must resolve to Accepted, reporting reuse rather than
 * expiry) - that resolver is PlanInviteTokens, written by a later section, and the obligation
 * to assert its ordering is owed there, not here.
 */
enum InviteState
{
    case Valid;
    case Expired;
    case Revoked;
    case Accepted;
}
