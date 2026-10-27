<?php

declare(strict_types=1);

namespace App\Http\Support;

/**
 * The one definition of "normalize a submitted email value" at the HTTP boundary, shared by
 * every FormRequest that validates an email field (via NormalizesEmailInput) and by the
 * auth-forgot-password rate limiter in AppServiceProvider::boot().
 *
 * Before this existed, strtolower(trim(...)) was an independent copy in LoginRequest,
 * RegisterRequest, ForgotPasswordRequest, ResetPasswordRequest and the limiter closure -
 * five places that had to keep agreeing by coincidence rather than by construction. The
 * limiter case is not cosmetic: the 3-per-hour-per-email limit in §8.2 is only
 * un-bypassable by casing because the limiter's key and ForgotPasswordRequest's validated
 * value are normalized the same way. One definition makes that structural.
 *
 * Deliberately not App\Domain\Users\Email::fromString(): that also validates and throws,
 * which is wrong for prepareForValidation() - a request with a malformed email must still
 * reach ValidEmail and come back as a 422, not blow up here before validation runs. This
 * class only normalizes; it has no opinion on whether the result is a valid address.
 */
final class EmailInput
{
    public static function normalize(string $email): string
    {
        return strtolower(trim($email));
    }
}
