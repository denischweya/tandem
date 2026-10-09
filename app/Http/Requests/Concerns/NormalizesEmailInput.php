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
 *
 * Exposed as normalizeEmailInput(), not as prepareForValidation() itself, and that is
 * deliberate: PHP resolves a method declared directly on a class over one supplied by a
 * trait it uses, silently - no error, no warning, no deprecation. A trait that defined
 * prepareForValidation() would be invisibly discarded the moment any using class declared
 * its own (RegisterRequest needs to, to also trim display_name - see its prepareForValidation()),
 * taking this normalization, and the invariants above that depend on it, with it. Naming
 * this method something else means every using class must call it explicitly from its own
 * prepareForValidation(), so a class that forgets to call it fails by leaving email
 * unnormalized - visible in the lowercasing and case-insensitive-uniqueness tests - rather
 * than by silently losing the override and staying green.
 */
trait NormalizesEmailInput
{
    protected function normalizeEmailInput(): void
    {
        $email = $this->input('email');

        if (is_string($email)) {
            $this->merge(['email' => EmailInput::normalize($email)]);
        }
    }
}
