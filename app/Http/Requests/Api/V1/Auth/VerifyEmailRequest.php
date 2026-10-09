<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use Illuminate\Foundation\Http\FormRequest;

final class VerifyEmailRequest extends FormRequest
{
    /**
     * The signature is the authorization. hasValidSignature() recomputes the HMAC from this
     * request's own URL and query, so a tampered or expired link fails here with a 403
     * before any rule runs and before any user is loaded.
     */
    public function authorize(): bool
    {
        return $this->hasValidSignature();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'id' => ['required', 'uuid'],
            'hash' => ['required', 'string'],
        ];
    }
}
