<?php

declare(strict_types=1);

namespace App\Domain\Plans;

/**
 * Blueprint §8. `archived` and `cancelled` are deliberately distinct: archiving is what
 * happens to a Plan nobody is left to own (spec §9.3 step 2's last resort), while
 * cancelling is a decision someone made about the event itself.
 */
enum PlanStatus: string
{
    case Active = 'active';
    case Archived = 'archived';
    case Cancelled = 'cancelled';
}
