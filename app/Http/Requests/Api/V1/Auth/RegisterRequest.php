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
     * Trims display_name before `required` sees it, for the same reason
     * UpdateProfileRequest does: DisplayName::fromString('   ') throws
     * InvalidArgumentException, which RegisterController calls with no try/catch, so
     * whitespace-only input would surface as a 500 rather than a 422 without this.
     *
     * Also where this class's own email normalization happens - see NormalizesEmailInput's
     * docblock for why that trait exposes normalizeEmailInput() rather than declaring
     * prepareForValidation() itself.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeEmailInput();

        $name = $this->input('display_name');

        if (is_string($name)) {
            $this->merge(['display_name' => trim($name)]);
        }
    }

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
     * `timezone` uses that first position DELIBERATELY, which is the other half of the same
     * rule: the one thing a client cannot work out for itself is that this field wants an IANA
     * identifier, and IanaTimeZone correctly declines to enumerate the ~400 values (the tz
     * database differs between machines, so a published enum would be a per-machine document -
     * exactly what spec §5's byte gate exists to prevent). So the client-facing sentence goes
     * where Scramble publishes it. The test is that the published description is that sentence
     * and nothing else.
     *
     * `locale` carries `required` alongside `sometimes`, matching UpdateProfileRequest. Those
     * two rules were asymmetric and the asymmetry was an oversight, not a design: Laravel's
     * ConvertEmptyStringsToNull middleware turns `""` into null before any rule runs, and null
     * fails `string`, so sending an empty locale was ALREADY a 422 here - `sometimes, string`
     * simply described that wrongly. tandem-shared's RegisterSchema had mirrored the written
     * rule and accepted `""`, which no client could then send. The published schema does not
     * move: `required` adds no minLength.
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
            /** An IANA time zone identifier, such as "Europe/London" or "America/New_York". */
            'timezone' => ['required', 'string', new IanaTimeZone],
            'locale' => ['sometimes', 'required', 'string', 'max:10'],
        ];
    }
}
