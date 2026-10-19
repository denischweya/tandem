<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use App\Domain\Plans\PlanPermissions;
use App\Domain\Plans\PlanStatus;
use App\Domain\Shared\Clock;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

final readonly class ArchivePlan
{
    public function __construct(
        private PlanRepository $plans,
        private RecordActivity $activity,
        private Clock $clock,
    ) {}

    /**
     * Owner only, through canArchive() rather than through an inline role comparison: an inline
     * check would be a second place the matrix can be read from, which is what spec §4.4 argues
     * against, and reusing Capability::DeletePlan because the truth tables happen to match would
     * hide a coupling that a later cycle splitting archive from delete would not see.
     *
     * No PlanNotEditable check, deliberately: archiving an archived Plan is a success (§5.3's
     * Allowed column), and the idempotence below is what makes it one.
     *
     * Idempotent in two parts. A second call returns the SAME archived_at - re-stamping it would
     * make the ordering and the feed lie about when the Plan ended - and writes NO second
     * activity row, because the activity feed is immutable (blueprint §51) and must not
     * accumulate duplicates because a client retried. The precedent for "a second valid call is
     * another success" is VerifyEmailController, which is idempotent for an already-verified
     * user.
     *
     * status and archived_at are written together because DeleteAccount::resolvePlanOwnership()
     * writes both, and a reader that checked only one would disagree with the other path.
     *
     * Carbon::instance(), not a bare assignment of Clock::now()'s DateTimeImmutable: archived_at
     * is cast 'datetime', which PHPStan/larastan types as Illuminate\Support\Carbon|null on the
     * property - a DateTimeImmutable does not satisfy that (measured: phpstan analyse --level=8
     * reports assign.propertyType on a bare assignment). CreatePlan's joined_at assignment is the
     * precedent for the same wrapping, for the same reason.
     *
     * @throws PlanNotVisible
     * @throws AuthorizationException
     */
    public function execute(User $actor, string $planId): PlanContext
    {
        $context = $this->plans->findVisibleTo($planId, $actor->id);

        if (! PlanPermissions::canArchive($context->role)) {
            throw new AuthorizationException;
        }

        if ($context->plan->status === PlanStatus::Archived->value) {
            return $context;
        }

        DB::transaction(function () use ($actor, $context): void {
            $context->plan->status = PlanStatus::Archived->value;
            $context->plan->archived_at = Carbon::instance($this->clock->now());
            $context->plan->save();

            $this->activity->execute(
                $context->plan->id,
                $actor->id,
                ActivityType::PlanArchived,
                null,
                null,
                [],
            );
        });

        return $context;
    }
}
