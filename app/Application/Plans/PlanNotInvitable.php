<?php

declare(strict_types=1);

namespace App\Application\Plans;

use RuntimeException;

/**
 * A Plan whose visibility is `private` cannot have anyone invited to it.
 *
 * Its own class and its own message rather than reusing PlanNotEditable, because the two say
 * different things and a client that cannot tell them apart cannot say anything useful: an
 * archived Plan is finished, a private Plan is not shared. §10.4 requires the private refusal to
 * carry "its own message", and spec §5's exception table does not list this class at all - see
 * this section's spec_defects note.
 *
 * 409 and not 403, for §5.3's reason: the refusal is about the PLAN'S STATE, not about who the
 * caller is. The owner of a private Plan is refused exactly as an admin is.
 *
 * Why the message names no remedy: `visibility` is not writable anywhere in Cycle 1 (§7.3
 * publishes it read-only), so "change its visibility" would be an instruction the API cannot
 * honour. Blueprint §135 asks for the action where there is one; here there is none, and saying
 * so plainly beats inventing one.
 */
final class PlanNotInvitable extends RuntimeException {}
