<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Concerns\NormalizesEmailInput;
use App\Http\Rules\IanaTimeZone;
use App\Http\Rules\ValidEmail;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rules\Password;

final class RegisterRequest extends FormRequest
{
    // The `unique` rule below and the users_email_lowercase CHECK both need the normalized
    // value - see NormalizesEmailInput for why this is lowercased before validation, not
    // after, and why that is now one shared implementation rather than this class's own.
    use NormalizesEmailInput;

    /**
     * Why the password rule is length-only, and why this note is in THIS docblock:
     *
     * No composition rules (mixed case, digits, symbols): NIST SP 800-63B advises against
     * them, and they measurably push users toward predictable substitutions.
     * ->uncompromised() is deliberately absent as well - it calls the Have I Been Pwned API,
     * which would put a network round trip inside the registration request and make CI
     * depend on an external service. Revisit with a locally shipped breach list.
     *
     * Two positions in this class are harvested by Scramble and published verbatim in
     * openapi.json, and this reasoning must stay out of both. Measured, not assumed:
     *
     *   - A comment immediately above a rules() array key becomes that field's client-facing
     *     `description`. Infer\Visitors\PhpDocResolver::enterNode() turns an Expr\ArrayItem's
     *     leading comments (// included) into its PHPDoc, and PhpDocSchemaTransformer then
     *     calls setDescription() with the summary. This is where the note used to live, which
     *     published it as the public description of `password`.
     *   - The class-level docblock becomes components.schemas.RegisterRequest.description.
     *     Verified by probe: moving the note there simply relocated the leak.
     *
     * A method docblock is not harvested (verified by the same probe), so it is the one place
     * near the rule where prose about the rule is safe. Anything written directly above a
     * rules() key, or on the class, is public API documentation - treat those two positions
     * accordingly.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** @format email */
            'email' => ['required', 'string', new ValidEmail, 'max:254', 'unique:users,email'],
            'password' => ['required', 'string', Password::min(12)],
            'display_name' => ['required', 'string', 'max:80'],
            'timezone' => ['required', 'string', new IanaTimeZone],
            'locale' => ['sometimes', 'string', 'max:10'],
        ];
    }
}
