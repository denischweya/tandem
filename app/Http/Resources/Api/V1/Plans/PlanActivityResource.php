<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Plans;

use App\Application\Plans\PlanActivitySummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanActivitySummary */
final class PlanActivityResource extends JsonResource
{
    /**
     * activity_type publishes ->value - see PlanSummary's own docblock (Task B) for why no
     * Resource field in this section is typed as the native enum. actor_name is null for two
     * different reasons a client cannot tell apart and does not need to (anonymised, or a
     * profile that never existed) - see PlanActivitySummary's producing repository.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'activity_type' => $this->activityType->value,
            'actor_id' => $this->actorId,
            'actor_name' => $this->actorName,
            'subject_type' => $this->subjectType,
            'subject_id' => $this->subjectId,
            'metadata' => $this->metadata,
            /** @format date-time */
            'created_at' => (string) $this->createdAt->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
