<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Plans;

use App\Application\Plans\PlanSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanSummary */
final class PlanResource extends JsonResource
{
    /**
     * group_id and cover_image_path are omitted, not published as null: spec §6.1 - nothing in
     * Cycle 1 can set either, and a permanently-null field documents a capability the API does
     * not have. status, visibility and viewer_role publish ->value: see PlanSummary's own
     * docblock for why none of the three is typed as the native enum here.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status->value,
            'visibility' => $this->visibility->value,
            'timezone' => $this->timezone,
            'owner_id' => $this->ownerId,
            'member_count' => $this->memberCount,
            'viewer_role' => $this->viewerRole->value,
            /** @format date-time */
            'archived_at' => $this->archivedAt === null ? null : (string) $this->archivedAt->format(DATE_RFC3339_EXTENDED),
            /** @format date-time */
            'created_at' => (string) $this->createdAt->format(DATE_RFC3339_EXTENDED),
            /** @format date-time */
            'updated_at' => (string) $this->updatedAt->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
