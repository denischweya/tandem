<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Models\User;

final readonly class GetPlan
{
    public function __construct(private PlanRepository $plans) {}

    /**
     * One statement, and spec §5.1's whole rule is in it. findVisibleTo() resolves the Plan and
     * the actor's membership together and throws PlanNotVisible for a malformed id, an unknown
     * Plan, an actor with no plan_members row and an actor whose row's status does not grant
     * access - so the 404 is decided in one place for every Plan endpoint rather than in nine
     * controllers, and no caller can accidentally answer 403 and leak the Plan's existence.
     *
     * A use case rather than a controller calling the repository directly, for two reasons that
     * outlive the one line: spec §4's Application/Plans list names GetPlan, and the arch rule
     * `expect('App\Http\Controllers')->not->toUse(['Illuminate\Database\Eloquent', 'App\Models',
     * 'App\Infrastructure'])` means a controller cannot name the repository's implementation at
     * all. The container resolves the interface; the controller names neither.
     *
     * @throws PlanNotVisible
     */
    public function execute(User $actor, string $planId): PlanContext
    {
        return $this->plans->findVisibleTo($planId, $actor->id);
    }
}
