<?php

declare(strict_types=1);

namespace App\Http\Rules;

use App\Domain\Users\Email;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Delegates to the domain value object rather than restating its rule, so there is one
 * definition of "is this an email" - the same pattern as IanaTimeZone, and for the same
 * reason.
 *
 * Laravel's 'email:rfc' and Email::fromString()'s filter_var(FILTER_VALIDATE_EMAIL) are
 * different validators, and the controller calls Email::fromString() with no try/catch, so
 * every address in the gap between them left the request as a 500 rather than a 422.
 * Measured examples: ada@localhost, ada@example, ada(comment)@example.test and
 * "ada bob"@example.test all pass email:rfc and all fail filter_var.
 *
 * Delegating closes the gap by construction rather than by coincidence: 'email:filter'
 * would also match filter_var today, but it is a second declaration of the rule and would
 * drift the moment Email::fromString() gains any check filter_var does not perform.
 */
final class ValidEmail implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute must be a valid email address.');

            return;
        }

        try {
            Email::fromString($value);
        } catch (InvalidArgumentException) {
            $fail('The :attribute must be a valid email address.');
        }
    }
}
