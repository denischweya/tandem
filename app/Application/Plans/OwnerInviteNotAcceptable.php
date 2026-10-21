<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * Human ruling (controller, 2026-10-20), carried here rather than re-argued: the migration
 * freeze forbids constraining plan_invites.role (a plain string column with no CHECK), so this
 * class is the only place §9.1's one-owner invariant can be protected on the join path.
 *
 * MemberRole::Owner is a legal enum case, so MemberRole::from($invite->role) would happily
 * return it and buy nothing against the two-owner state - the hazard is not a validation gap,
 * it is that accepting such an invite writes a SECOND role = owner, status = active row for a
 * Plan that already has one (plans.owner_id names the first). The check in AcceptInvite is
 * therefore a plain string comparison against the literal 'owner', never from() or tryFrom().
 *
 * 409, not 404 and not 422: the request is well-formed and the caller did nothing wrong - the
 * defect is in a row somebody else wrote, by direct database access, since nothing in this API
 * can mint an owner-role invite (InvitePlanMemberRequest's in:admin,member and
 * PlanPermissions::canInviteWithRole() both forbid it). 422 is for a wrong field, and there is no
 * field to blame; 404 would be indistinguishable from an ordinary dead invite, which is wrong
 * here precisely because this case is NOT ordinary control flow.
 *
 * And that is why, unlike every other exception in this directory, this one is deliberately
 * ABSENT from bootstrap/app.php's dontReport() list: a non-member probing a Plan id or an
 * expired link is expected traffic; a plan_invites row carrying role = 'owner' is a
 * data-integrity signal that should be reported and alerted on. Suppressing it would mean the
 * invite becomes permanently unacceptable, the invitee is told only "conflict", and nobody is
 * ever told a row exists that the API should never have written. The render() closure pairs
 * this with a Log::warning() at the throw site for the same reason.
 *
 * For a later cycle: the right fix is a CHECK constraint on plan_invites.role (or narrowing it
 * to the roles an invite may legitimately carry), which would make this class unreachable
 * defensive code. Keep it, and say so in this comment rather than deleting it - an unreachable
 * guard whose comment is silent about why it is unreachable is the shape a future tidy-up
 * deletes by mistake.
 */
final class OwnerInviteNotAcceptable extends RuntimeException {}
