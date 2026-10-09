<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Auth;

use App\Http\Requests\Concerns\NormalizesEmailInput;
use App\Http\Rules\ValidEmail;
use Illuminate\Foundation\Http\FormRequest;

final class LoginRequest extends FormRequest
{
    use NormalizesEmailInput;

    /**
     * No Password::min() on `password` here, deliberately: a rule on the *submitted*
     * password would reject a sign-in attempt for a legacy credential before checking it,
     * and the length of a submitted password is not this endpoint's business.
     *
     * This note lives in the method docblock rather than above the rules() array key, or on
     * the class, because RegisterRequest's review found both of those positions are
     * harvested by Scramble and published verbatim in openapi.json - a comment directly
     * above a rules() key becomes that field's description, and a class-level docblock
     * becomes the schema's description. A method docblock is not harvested.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** @format email */
            'email' => ['required', 'string', new ValidEmail, 'max:254'],
            'password' => ['required', 'string'],
            'install_id' => ['sometimes', 'required', 'string', 'max:255'],
            'platform' => ['sometimes', 'required', 'string', 'in:ios,android,web'],
        ];
    }
}
