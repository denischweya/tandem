<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\Capability;
use App\Domain\Plans\PlanPermissions;
use App\Domain\Plans\PlanStatus;
use App\Domain\Shared\TimeZoneId;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;

final readonly class UpdatePlan
{
    public function __construct(
        private PlanRepository $plans,
        private RecordActivity $activity,
    ) {}

    /**
     * Each parameter is nullable and null means "not supplied", which is what makes this a PATCH
     * rather than a PUT - UpdateProfile's shape, and the same reasoning: no field of a Plan has
     * "cleared" as a meaningful state, so nothing is lost by that reading. UpdatePlanRequest's
     * rules are sometimes|required, so an empty string has already been refused with a 422
     * before this method is reached.
     *
     * The role check comes BEFORE the archived check, deliberately: a caller who may never edit
     * gets the same answer whatever the Plan's lifecycle is doing. Neither status leaks anything
     * - both are reachable only by a member, who can already read the Plan's status through GET
     * - so the choice is about having one fixed order rather than two. UpdatePlanTest's "answers
     * 403 and not 409 when a plain member edits an archived Plan" is the assertion that pins it.
     *
     * The state check reads "anything but active", not "archived" specifically - see
     * PlanNotEditable's docblock. Nothing in Wave 2 ever writes PlanStatus::Cancelled, so the
     * two readings answer identically today.
     *
     * Nothing is written when nothing changed, and getDirty() is what decides. The load-bearing
     * reason is the activity feed's immutability (blueprint §51): a feed entry for a change that
     * changed nothing can never be corrected, and this short-circuit is what a no-op PATCH relies
     * on to write none. (Eloquent's own Model::save() already gates on isDirty() before touching
     * anything, including updated_at - measured by removing this block and observing that
     * updated_at does NOT move, only the activity feed gains a spurious row - so this
     * short-circuit is not what protects plans.updated_at from a no-op write; it is what
     * protects the activity feed from one.) Spec §8.2's role change already takes this line for
     * "a change that is already true".
     *
     * array_keys(getDirty()) is exactly the names of the attributes whose value actually
     * differs, in the order they are assigned below - which is what spec §11.1's
     * {"fields": [...]} metadata is, and never the values.
     *
     * @throws PlanNotVisible
     * @throws AuthorizationException
     * @throws PlanNotEditable
     */
    public function execute(
        User $actor,
        string $planId,
        ?string $title,
        ?string $description,
        ?TimeZoneId $timezone,
    ): PlanContext {
        $context = $this->plans->findVisibleTo($planId, $actor->id);

        if (! PlanPermissions::allows($context->role, Capability::EditPlan)) {
            throw new AuthorizationException;
        }

        if ($context->plan->status !== PlanStatus::Active->value) {
            throw new PlanNotEditable;
        }

        $plan = $context->plan;

        if ($title !== null) {
            $plan->title = $title;
        }

        if ($description !== null) {
            $plan->description = $description;
        }

        if ($timezone !== null) {
            $plan->timezone = $timezone->toString();
        }

        $changed = array_keys($plan->getDirty());

        if ($changed === []) {
            return $context;
        }

        DB::transaction(function () use ($actor, $plan, $changed): void {
            $plan->save();

            $this->activity->execute(
                $plan->id,
                $actor->id,
                ActivityType::PlanUpdated,
                null,
                null,
                ['fields' => $changed],
            );
        });

        return $context;
    }
}
