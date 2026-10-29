<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Plans;

use App\Application\Plans\PlanMessageSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanMessageSummary */
final class PlanMessageResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        // Build reactions array with ternary+cast to avoid Scramble inferring an unresolved
        // template type from the PlanMessageReactionSummary[] property. The pattern matches
        // PlanActivityController's device for CursorPage::nextCursor: a ternary that ensures
        // the accessed property is freshly typed by the context, not by the DTO.
        $reactions = $this->reactions === [] ? [] : array_map(
            static fn ($r) => [
                'reaction' => (string) $r->reaction,
                'count' => (int) $r->count,
                'reacted_by_viewer' => (bool) $r->reactedByViewer,
            ],
            $this->reactions,
        );

        return [
            'id' => $this->id,
            'plan_id' => $this->planId,
            'user_id' => $this->userId,
            'body' => $this->body,
            /** @format date-time */
            'edited_at' => $this->editedAt === null ? null : (string) $this->editedAt->format(DATE_RFC3339_EXTENDED),
            /** @format date-time */
            'created_at' => (string) $this->createdAt->format(DATE_RFC3339_EXTENDED),
            /** @format date-time */
            'updated_at' => (string) $this->updatedAt->format(DATE_RFC3339_EXTENDED),
            'reactions' => $reactions,
        ];
    }
}
