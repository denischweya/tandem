<?php

declare(strict_types=1);

namespace App\Http\Rules;

use App\Domain\Shared\PageCursor;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use InvalidArgumentException;

/**
 * Delegates to the value object rather than restating its rule, so there is one definition of
 * "is this a cursor" - the same pattern as ValidEmail and IanaTimeZone, and for the same
 * reason.
 *
 * Without a rule, a tampered ?cursor= reaches PageCursor::fromString() inside the controller
 * and leaves the request as an uncaught InvalidArgumentException, which bootstrap/app.php does
 * not map: that is a 500 with {"message": "Server Error"}, for a client-supplied value. This
 * is the same shape as Wave 1a finding I1, where email:rfc and Email::fromString() disagreed
 * and the gap was a reachable 500 rather than a 422.
 *
 * The non-string branch is not defensive padding: ?cursor[]=x arrives as an array, and
 * PageCursor::fromString() is typed string, so without the guard the rule itself raises a
 * TypeError.
 */
final class ValidCursor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value)) {
            $fail('The :attribute is not a valid page cursor.');

            return;
        }

        try {
            PageCursor::fromString($value);
        } catch (InvalidArgumentException) {
            $fail('The :attribute is not a valid page cursor.');
        }
    }
}
