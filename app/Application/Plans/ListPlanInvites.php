<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\Capability;
use App\Domain\Plans\PlanPermissions;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;

final readonly class ListPlanInvites
{
    public function __construct(
        private PlanRepository $plans,
        private InviteRepository $invites,
    ) {}

    /**
     * findVisibleTo() first, which is what makes a non-member's refusal indistinguishable from
     * an unknown Plan's: only a caller who has already proved they can see the Plan can reach
     * the 403 below.
     *
     * No archived check, deliberately. The archived refusal is about WRITES; a reader who
     * cannot list an archived Plan's invites cannot see what was revoked, and DELETE on the
     * same {plan} does answer 409.
     *
     * @return list<PlanInviteView>
     *
     * @throws PlanNotVisible
     * @throws AuthorizationException
     */
    public function execute(User $actor, string $planId): array
    {
        $context = $this->plans->findVisibleTo($planId, $actor->id);

        // 403, not 404: this member can plainly see the Plan, so there is nothing left to
        // disclose. The capability is InviteMembers rather than a list-specific one: the
        // invite list carries addresses the inviter typed, which are personal data about
        // people who are not members, so the people who may read it are exactly the people who
        // may create it. The owner holds it too, through the additive role hierarchy.
        if (! PlanPermissions::allows($context->role, Capability::InviteMembers)) {
            throw new AuthorizationException;
        }

        // $context->plan->id rather than $planId: the repository has already proved this one
        // exists, and passing the proved value keeps the malformed-id branch of listForPlan()
        // unreachable from here.
        return $this->invites->listForPlan($context->plan->id);
    }
}
