<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Concerns\NormalizesEmailInput;
use App\Http\Rules\ValidEmail;
use Illuminate\Foundation\Http\FormRequest;

final class ForgotPasswordRequest extends FormRequest
{
    use NormalizesEmailInput;

    protected function prepareForValidation(): void
    {
        $this->normalizeEmailInput();
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            /** @format email */
            'email' => ['required', 'string', new ValidEmail, 'max:254'],
        ];
    }
}
