<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1;

use App\Http\Rules\IanaTimeZone;
use Illuminate\Foundation\Http\FormRequest;

final class UpdateProfileRequest extends FormRequest
{
    /**
     * email and email_verified are absent deliberately, not forgotten: changing an address
     * needs a re-verification flow no cycle has specified, and verification state is never
     * client-supplied.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'display_name' => ['sometimes', 'required', 'string', 'max:80'],
            'timezone' => ['sometimes', 'required', 'string', new IanaTimeZone],
            'locale' => ['sometimes', 'required', 'string', 'max:10'],
        ];
    }

    /**
     * Trims display_name before `required` sees it. DisplayName::fromString('   ') throws
     * InvalidArgumentException, which would surface as a 500 rather than a 422 - trimming
     * here means whitespace-only input is rejected by `required` before the value object
     * ever sees it.
     */
    protected function prepareForValidation(): void
    {
        $name = $this->input('display_name');

        if (is_string($name)) {
            $this->merge(['display_name' => trim($name)]);
        }
    }
}
