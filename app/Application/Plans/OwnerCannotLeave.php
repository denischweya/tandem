<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * The one refusal in Wave 2 that does not reuse Laravel's generic 403 body, and the reason is
 * that it is the one refusal with an exit. Blueprint §135 asks actionable errors to say what to
 * do; "This action is unauthorized." told an owner nothing, and the three things they can
 * actually do - transfer ownership (spec §9.3), archive the Plan (§7.4), delete it (§7.3) - are
 * what the sentence points at.
 *
 * It collapses nothing, unlike the other exceptions in this directory: there is exactly one way
 * to reach it, because canLeave() is a function of one role and plans.owner_id is a single
 * NOT NULL column. "The last owner cannot leave" needs no member count and no
 * is-this-the-last flag; the name of the rule presupposes a Plan can have several owners and
 * the schema forbids it (§9.1).
 */
final class OwnerCannotLeave extends RuntimeException {}
