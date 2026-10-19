<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * One exception, one answer: a Plan that is not active is administratively read-only, and the
 * refusal is 409 Conflict rendered by one closure in bootstrap/app.php.
 *
 * Why 409 rather than a status that already exists. 404 would lie - the member can see the Plan
 * and GET works, so answering 404 to PATCH and 200 to GET on the same path is incoherent. 403
 * attributes the refusal to who the caller is, when it is about the Plan's state: the owner of
 * an archived Plan is refused exactly as a member is. 422 is for a request whose fields are
 * wrong, and this codebase already has one overloaded 422 - InvalidPasswordResetToken, where
 * errors.token carries the same sentence as message and every client has to special-case it -
 * which is the trade that case teaches against.
 *
 * The Plan's state is read as "anything but active" rather than as "archived" specifically.
 * §7.3 ships no route that writes PlanStatus::Cancelled, so there is no observable difference
 * between the two readings in Wave 2, but "anything but active" is the one that stays correct
 * the moment a later cycle starts writing it. The members section's own role-change and
 * remove-member refusals raise this same exception on this same predicate for exactly this
 * reason, so that PATCH /plans/{plan} and PATCH /plans/{plan}/members/{user} cannot disagree
 * about whether a cancelled Plan is editable - a ruling this class adopts rather than one it
 * originates, since those use cases are not yet part of this codebase.
 *
 * Refusing on state rather than on identity is also what keeps the read-only rule from being an
 * authorization hole: a Plan archived by spec §9.3 branch 3 has no active owner row at all - its
 * owner is soft-deleted and every membership was set to left - so PATCH, invite, role change and
 * removal have no possible authorized caller on exactly the Plans that reach that state.
 */
final class PlanNotEditable extends RuntimeException {}
