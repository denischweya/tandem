<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Plans;

use App\Application\Plans\PlanMessageSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanMessageSummary */
final class PlanMessageResource extends JsonResource
{
    /**
     * user_id and user_display_name are both nullable, and both null together for the one
     * ordinary reason: the author deleted their account and §9.3's anonymisation nulled the
     * column (ADR 0012). user_display_name can also be null on its own, for a user_id whose
     * user_profiles row is gone - a distinction a client cannot make and does not need to,
     * exactly as PlanActivityResource records for actor_name.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'plan_id' => $this->planId,
            'user_id' => $this->userId,
            'user_display_name' => $this->userDisplayName,
            'body' => $this->body,
            /** @format date-time */
            'edited_at' => $this->editedAt === null ? null : (string) $this->editedAt->format(DATE_RFC3339_EXTENDED),
            /** @format date-time */
            'created_at' => (string) $this->createdAt->format(DATE_RFC3339_EXTENDED),
            /** @format date-time */
            'updated_at' => (string) $this->updatedAt->format(DATE_RFC3339_EXTENDED),
            'reactions' => PlanMessageReactionResource::collection($this->reactions),
        ];
    }
}
