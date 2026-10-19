<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\MemberRole;
use App\Domain\Plans\MemberStatus;
use App\Domain\Plans\PlanStatus;
use App\Domain\Plans\Visibility;
use App\Domain\Shared\Clock;
use App\Domain\Shared\TimeZoneId;
use App\Models\Plan;
use App\Models\PlanMember;
use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class CreatePlan
{
    public function __construct(
        private PlanRepository $plans,
        private RecordActivity $activity,
        private Clock $clock,
    ) {}

    /**
     * Three writes, one transaction, and all three are required for the Plan to be coherent.
     *
     * Step 2 - the owner's own plan_members row - is not bookkeeping. Without it spec §9.3's
     * Active-only successor logic, GET /plans (which selects through plan_members),
     * GET /plans/{plan} (which resolves the actor's role from plan_members) and §9.1's
     * ownership invariant all see the creator as a non-member of their own Plan.
     *
     * Every column is assigned by name rather than from an array. Plan and PlanMember are both
     * $guarded = ['*'], so ::create($validated) throws MassAssignmentException - but the reason
     * to assign by name is stronger than that: `['*']` blocks a SUPPLIED attribute and ignores
     * an OMITTED one, so an omitted status would silently take the plan_members column default
     * 'invited' and an omitted joined_at would silently be NULL. Both are assigned below, and
     * CreatePlanTest asserts both values rather than the absence of a client-supplied one.
     *
     * notification_settings is deliberately not assigned: it has no writer anywhere in Cycle 1
     * (blueprint §55's preferences are a notifications-cycle concern) and the column is NOT
     * NULL DEFAULT '{}', so the default is the intended value rather than an accident.
     *
     * The activity write is inside the transaction, so a Plan that was not created has no
     * "created" row and the feed can never describe something that did not happen - the rule
     * Register::execute() applies to its outbox event.
     *
     * The response is read back through findVisibleTo() after the commit, for two reasons: the
     * resource publishes member_count and viewer_role, which only the membership-resolving
     * query produces; and if step 2 were ever dropped, findVisibleTo() would throw and this
     * endpoint would answer 404 rather than return a Plan whose creator is not a member of it.
     * That is a loud failure on purpose, and Task B's break step observes it.
     */
    public function execute(User $owner, string $title, ?string $description, TimeZoneId $timezone): PlanContext
    {
        $planId = DB::transaction(function () use ($owner, $title, $description, $timezone): string {
            $now = $this->clock->now();

            $plan = new Plan;
            $plan->owner_id = $owner->id;
            $plan->title = $title;
            $plan->description = $description;
            $plan->timezone = $timezone->toString();
            $plan->status = PlanStatus::Active->value;
            $plan->visibility = Visibility::InviteOnly->value;
            $plan->save();

            $member = new PlanMember;
            $member->plan_id = $plan->id;
            $member->user_id = $owner->id;
            $member->role = MemberRole::Owner->value;
            $member->status = MemberStatus::Active->value;
            // Carbon::instance(), not a bare assignment of $now: $now is Clock::now()'s
            // DateTimeImmutable, and PlanMember's joined_at is cast 'datetime', which
            // PHPStan/larastan types as Illuminate\Support\Carbon|null on the property - a
            // DateTimeImmutable does not satisfy that (measured: phpstan analyse --level=8
            // reports assign.propertyType on a bare `$member->joined_at = $now;`).
            // Carbon::instance() wraps any DateTimeInterface without losing the instant.
            $member->joined_at = Carbon::instance($now);
            $member->save();

            $this->activity->execute($plan->id, $owner->id, ActivityType::PlanCreated, null, null, []);

            return $plan->id;
        });

        return $this->plans->findVisibleTo($planId, $owner->id);
    }
}
