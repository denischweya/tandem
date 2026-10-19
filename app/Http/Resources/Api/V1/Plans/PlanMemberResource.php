<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Plans;

use App\Application\Plans\PlanMemberView;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanMemberView */
final class PlanMemberResource extends JsonResource
{
    /**
     * Never the user's email (blueprint §126). role and status publish ->value - see
     * PlanSummary's docblock (Task B) for why no Resource field in this section is typed as
     * the native enum.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'user_id' => $this->userId,
            'display_name' => $this->displayName,
            'role' => $this->role->value,
            'status' => $this->status->value,
            /** @format date-time */
            'joined_at' => (string) $this->joinedAt->format(DATE_RFC3339_EXTENDED),
        ];
    }
}
