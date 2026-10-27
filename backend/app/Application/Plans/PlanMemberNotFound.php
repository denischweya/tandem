<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * 404 with {"message":"That person is not a member of this Plan."}, rendered in
 * bootstrap/app.php.
 *
 * One exception for a malformed {user}, an unknown user, a user who is not a member of this
 * Plan, a member whose status is not `active`, and - on the transfer path - a member whose
 * account is soft-deleted or suspended. §9.3: an ineligible target answers 404 identically to
 * a non-member, "because 'that person's account is suspended' is not something a Plan owner
 * needs told, and telling them leaks an account state".
 *
 * Why this body is precise while PlanNotVisible's is vague: §5.2's ordering is the guarantee.
 * findVisibleTo() runs first in every use case, so this is only ever reachable by a caller who
 * has already proved they can see the Plan and hold the role for the action - there is nothing
 * left to disclose at that point, and "an owner who can plainly see their own Plan being told
 * 'Plan not found' is the kind of refusal blueprint §135 exists to prevent".
 */
final class PlanMemberNotFound extends RuntimeException {}
