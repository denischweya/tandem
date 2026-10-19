<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\MemberRole;
use App\Domain\Plans\PlanStatus;
use App\Domain\Plans\Visibility;
use Illuminate\Support\Carbon;

/**
 * The flat, Resource-ready read model for one Plan. Deliberately not PlanContext: PlanContext
 * nests a Plan model (public Plan $plan), and a JsonResource's @mixin must resolve every
 * toArray() key to a DECLARED property on the mixed-in class for PHPStan level 8 to check it
 * (D-R1) - $this->id on a PlanContext mixin would silently resolve to nothing (a PHP warning,
 * null at runtime), because PlanContext has no $id of its own.
 */
final readonly class PlanSummary
{
    public function __construct(
        public string $id,
        public string $title,
        public ?string $description,
        public PlanStatus $status,
        public Visibility $visibility,
        public string $timezone,
        public string $ownerId,
        public int $memberCount,
        public MemberRole $viewerRole,
        public ?Carbon $archivedAt,
        public Carbon $createdAt,
        public Carbon $updatedAt,
    ) {}

    /**
     * For the single-Plan show route (GET /plans/{plan}), which this section does not own.
     * PlanContext already carries memberCount and role resolved at the authorization boundary
     * (findVisibleTo()), so there is nothing left to compute here.
     */
    public static function fromContext(PlanContext $context): self
    {
        $plan = $context->plan;

        return new self(
            id: $plan->id,
            title: $plan->title,
            description: $plan->description,
            status: PlanStatus::from($plan->status),
            visibility: Visibility::from($plan->visibility),
            timezone: $plan->timezone,
            ownerId: $plan->owner_id,
            memberCount: $context->memberCount,
            viewerRole: $context->role,
            archivedAt: $plan->archived_at,
            createdAt: $plan->created_at,
            updatedAt: $plan->updated_at,
        );
    }
}
