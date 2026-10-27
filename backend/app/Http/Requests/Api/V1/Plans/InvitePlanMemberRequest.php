<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Plans;

use App\Domain\Plans\MemberRole;
use App\Http\Requests\Concerns\NormalizesEmailInput;
use App\Http\Rules\ValidEmail;
use Illuminate\Foundation\Http\FormRequest;

final class InvitePlanMemberRequest extends FormRequest
{
    use NormalizesEmailInput;

    // A comment above the `use`, not a docblock on the class: a FormRequest's CLASS docblock is
    // harvested by Scramble into components.schemas.InvitePlanMemberRequest.description -
    // measured by probe - so internal rationale on the class is published API documentation.

    /**
     * Its own prepareForValidation() calling normalizeEmailInput(), and the trait deliberately
     * does not declare prepareForValidation() itself: PHP resolves a method declared on a class
     * over one supplied by a trait, SILENTLY - no error, no warning - so a trait that supplied
     * this method would be discarded the moment a using class declared its own. That hazard was
     * found before it shipped in Wave 1a Task 7, and this is exactly the class it was about,
     * because this class needs its own prepareForValidation() anyway.
     */
    protected function prepareForValidation(): void
    {
        $this->normalizeEmailInput();
    }

    /**
     * Why `email` goes through ValidEmail and the normalizing trait - not stylistic consistency,
     * two measured reasons:
     *
     *   1. Laravel's email:rfc and App\Domain\Users\Email::fromString()'s
     *      filter_var(FILTER_VALIDATE_EMAIL) gate are DIFFERENT validators, and a controller
     *      calls Email::fromString() with no try/catch - so every address in the gap between them
     *      (measured: ada@localhost, ada@example, ada(comment)@example.test and
     *      "ada bob"@example.test) left the request as a reachable 500 rather than a 422. The
     *      rule delegating to the value object closes the gap by construction; 'email:filter'
     *      would match filter_var today and drift the moment the value object gains a check.
     *   2. plan_invites carries a byte-exact database CHECK - email IS NULL OR email =
     *      lower(email) - so an un-normalised mixed-case address is a 23514 constraint violation
     *      surfacing as a 500. EmailInput::normalize() is one function body with existing call
     *      sites precisely so the normalisation cannot drift, and this is the next one.
     *
     * Why `role` is a string in: rule built from MemberRole's cases, and NOT Rule::enum() or a
     * native enum type: Scramble's EnumTransformer::addEnumDescription() harvests an enum's CLASS
     * docblock into the published schema description, unconditionally and byte-identically under
     * every enum_cases_description_strategy value (measured against all four), and MemberRole's
     * docblock names the arch rule and a test file. A string in: rule publishes
     * {"type":"string","enum":["admin","member"]} from the rule text alone and reaches no enum
     * class at all. The values still come from the enum, so a renamed case breaks here rather
     * than silently accepting a value nothing recognises.
     *
     * Why `owner` is absent: an owner-role invite would be an ownership transfer by the back
     * door, bypassing §9.3's checks entirely. The narrower rule - that an Admin may mint `member`
     * invites only - is PlanPermissions::canInviteWithRole()'s, because it needs the actor's role,
     * which a FormRequest does not have.
     *
     * Both fields are `sometimes`, which is what makes them optional in the published schema:
     * OpenApiContractTest derives each schema's expected `required` list from its own `sometimes`
     * rules, so this class must be added to that test's $classes map or it is unchecked and
     * nothing else notices. `required` beside `sometimes` then means "not empty IF present",
     * which is what Laravel's global ConvertEmptyStringsToNull makes necessary: an empty-string
     * email arrives as null and must be a 422, not a link-only invite.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** @format email */
            'email' => ['sometimes', 'required', 'string', new ValidEmail, 'max:254'],
            'role' => ['sometimes', 'required', 'string', 'in:'.MemberRole::Admin->value.','.MemberRole::Member->value],
        ];
    }
}
