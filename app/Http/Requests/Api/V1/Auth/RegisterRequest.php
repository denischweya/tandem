<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Rules\IanaTimeZone;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    /**
     * Lowercased before validation, not after: the `unique` rule below and the
     * users_email_lowercase CHECK both need the normalized value, and Email::fromString()
     * downstream would otherwise produce a value that fails the uniqueness check it already
     * passed.
     */
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => strtolower(trim($email))]);
        }
    }

    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email:rfc', 'max:254', 'unique:users,email'],
            // Length only, no composition rules: NIST SP 800-63B advises against them, and
            // they measurably push users toward predictable substitutions. ->uncompromised()
            // is deliberately absent: it calls the Have I Been Pwned API, which would put a
            // network round trip inside the registration request and make CI depend on an
            // external service. Revisit with a locally shipped breach list.
            'password' => ['required', 'string', Password::min(12)],
            'display_name' => ['required', 'string', 'max:80'],
            'timezone' => ['required', 'string', new IanaTimeZone],
            'locale' => ['sometimes', 'string', 'max:10'],
        ];
    }
}
