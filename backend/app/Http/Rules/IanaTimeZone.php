<?php

declare(strict_types=1);

namespace App\Http\Rules;

use App\Domain\Shared\TimeZoneId;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Delegates to the domain value object rather than restating its rule, so there is one
 * definition of "is an IANA identifier".
 *
 * Deliberately NOT Rule::in(DateTimeZone::listIdentifiers()): that would enumerate ~400
 * values into openapi.json, and the tz database version differs between machines - which
 * would make the byte-equality gate fail depending on whose PHP generated the document.
 * Exactly the class of leak bin/generate-openapi.sh exists to prevent, so it must not be
 * reintroduced through a validation rule.
 */
final class IanaTimeZone implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be an IANA timezone identifier.');

            return;
        }

        try {
            TimeZoneId::fromString($value);
        } catch (InvalidArgumentException) {
            $fail('The :attribute must be an IANA timezone identifier.');
        }
    }
}
