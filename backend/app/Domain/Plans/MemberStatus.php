<?php

declare(strict_types=1);

namespace App\Domain\Plans;

/**
 * Blueprint §9. The lifecycle of one person's membership of one Plan, in order: an invite
 * is issued (`invited`), accepted (`active`), and then ends either by their own choice
 * (`left`) or by someone else's (`removed`). Account deletion uses `left` - spec §9.3 step
 * 2 treats a departing user as having left every Plan, not as having been removed from it.
 *
 * `invited` is plan_members.status's column default, which is why it is first: a row exists
 * from the moment the invitation is sent, before anyone has accepted anything.
 */
enum MemberStatus: string
{
    case Invited = 'invited';
    case Active = 'active';
    case Left = 'left';
    case Removed = 'removed';
}
