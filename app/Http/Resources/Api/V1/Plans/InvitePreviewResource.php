<?php

declare(strict_types=1);

namespace App\Http\Resources\Api\V1\Plans;

use App\Application\Plans\InvitePreview;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** @mixin InvitePreview */
final class InvitePreviewResource extends JsonResource
{
    /**
     * §10.3: an unauthenticated caller sees this before proving anything. No email, no token,
     * no role - only enough to render "Ada invited you to Camping trip (4 members)". FOUR
     * fields and no more; anything added beyond them is a disclosure decision and must come
     * back to blueprint §126/§154 first.
     *
     * Read through $this, never through a local copy of $this->resource - see
     * PlanInviteResource's docblock for the measured reason the mixin above is load-bearing
     * here too.
     *
     * The `data` envelope below is Laravel's default JsonResource::$wrap. Nothing here calls
     * withoutWrapping().
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'plan_id' => $this->planId,
            'plan_title' => $this->planTitle,
            'inviter_name' => $this->inviterName,
            'member_count' => $this->memberCount,
        ];
    }
}
