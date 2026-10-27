<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class RefreshTokenRequest extends FormRequest
{
    /**
     * Shared by POST /auth/refresh and POST /auth/logout, which take the same single field.
     * Two classes with identical rules() bodies were considered, on the reasoning that one
     * endpoint's token shape might later change without the other's - rejected as speculative
     * (YAGNI): the two tokens are the same token, issued by one code path, and if they ever
     * diverge that is the moment to split this class, not before.
     *
     * size:64 matches bin2hex(random_bytes(32)), what RefreshTokenIssuer actually mints. A
     * rule that nothing this API ever issued can fail turns away malformed input before a
     * database lookup, without telling the caller anything about which tokens exist.
     *
     * This reasoning is in the METHOD docblock, not on the class and not above the
     * 'refresh_token' key, because RegisterRequest's review measured both of those positions
     * being harvested by Scramble and published verbatim in openapi.json - a comment above a
     * rules() key becomes that field's public description, a class docblock becomes the
     * schema's. A method docblock is not harvested.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'refresh_token' => ['required', 'string', 'size:64'],
        ];
    }
}
