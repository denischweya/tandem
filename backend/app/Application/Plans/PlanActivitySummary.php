<?php

declare(strict_types=1);

namespace App\Application\Plans;

use App\Domain\Plans\ActivityType;
use Illuminate\Support\Carbon;

/**
 * The flat, Resource-ready read model for one activity row. actorName is resolved outside this
 * class - by the repository, from a batched lookup (baseline §11.3) - so there is nowhere here
 * for a per-row N+1 to hide.
 */
final readonly class PlanActivitySummary
{
    /** @param array<string, mixed> $metadata */
    public function __construct(
        public string $id,
        public ActivityType $activityType,
        public ?string $actorId,
        public ?string $actorName,
        public ?string $subjectType,
        public ?string $subjectId,
        public array $metadata,
        public Carbon $createdAt,
    ) {}
}
