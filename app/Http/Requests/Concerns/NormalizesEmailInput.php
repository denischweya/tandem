<?php

declare(strict_types=1);

namespace App\Http\Requests\Concerns;

use App\Http\Support\EmailInput;

/**
 * Lowercases and trims the `email` input before validation, not after - a FormRequest's own
 * `unique` rule (RegisterRequest) and the users_email_lowercase CHECK constraint both need
 * the normalized value, and App\Domain\Users\Email::fromString() downstream would otherwise
 * see a value that could fail a uniqueness or lookup check it already passed.
 *
 * One trait for all four auth FormRequests that take an email, so this behaviour - and the
 * guard against a non-string value, which must be left alone here so the 'string' rule can
 * reject it with a 422 naming the field, rather than this method crashing on a non-string
 * strtolower() call - has exactly one implementation. See EmailInput for why the actual
 * normalization formula is a further-shared static method rather than inlined here.
 */
trait NormalizesEmailInput
{
    protected function prepareForValidation(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => EmailInput::normalize($email)]);
        }
    }
}
