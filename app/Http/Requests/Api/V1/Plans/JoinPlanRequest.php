<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Plans;

use Illuminate\Foundation\Http\FormRequest;

final class JoinPlanRequest extends FormRequest
{
    // A comment and not a class docblock: a FormRequest's CLASS docblock is harvested by Scramble
    // into components.schemas.JoinPlanRequest.description - measured by probe - so internal
    // rationale on the class is published API documentation.

    /**
     * size:64 matches bin2hex(random_bytes(32)), which is what PlanInviteTokens::issue() mints -
     * the same rule RefreshTokenRequest carries on the same token shape. A rule nothing this API
     * ever issued can pass turns away malformed input before a database lookup, without telling
     * the caller anything about which tokens exist.
     *
     * NO hex rule, deliberately. A 64-character non-hex token is a miss on a varchar(255) column,
     * not a database error, and it must answer the same 404 as every other unusable token: a
     * shape guard that produced a 422 for a well-formed-but-wrong token would hand a caller a way
     * to tell "this is not a token" from "this token is dead", which is exactly what
     * InviteNotUsable exists to prevent.
     *
     * There is no `sometimes` here, so the published `required` list is ["token"].
     *
     * This reasoning is in the METHOD docblock, not on the class and not above the 'token' key: a
     * comment above a rules() key becomes that field's public description, and a class docblock
     * becomes the schema's. A method docblock is not harvested.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'token' => ['required', 'string', 'size:64'],
        ];
    }
}
