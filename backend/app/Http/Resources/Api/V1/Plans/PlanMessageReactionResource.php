<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Plans;

use App\Application\Plans\PlanMessageReactionSummary;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin PlanMessageReactionSummary */
final class PlanMessageReactionResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'reaction' => $this->reaction,
            'count' => $this->count,
            'reacted_by_viewer' => $this->reactedByViewer,
        ];
    }
}
