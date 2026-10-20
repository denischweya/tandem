<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Plans;

use Illuminate\Foundation\Http\FormRequest;

final class ChangeMemberRoleRequest extends FormRequest
{
    /**
     * `role` is `required` rather than `sometimes|required`: there is exactly one writable
     * field here, so an empty body has nothing to mean. (Contrast UpdatePlanRequest, where
     * `sometimes` is what makes omitting a key leave the value unchanged.) Both blank spellings
     * arrive as present-and-null - TrimStrings runs before ConvertEmptyStringsToNull in the
     * global stack, so `'   '` is trimmed to `''` and then converted - and `required` refuses
     * null through its own is_null() branch, so there is no third blank shape a client can send.
     *
     * 'in:owner,admin,member' as a string literal, matching LoginRequest's 'in:ios,android,web'.
     * Not Rule::in(array_column(MemberRole::cases(), 'value')) and not a native enum rule: an
     * enum typed into a rules() array publishes MemberRole's class docblock as the schema
     * description, unconditionally (baseline S-9), and the dynamic array is not a value Scramble
     * can read statically, so it would publish a bare `string` and silently weaken the
     * documented contract. The cost of the literal is that it cannot see a renamed or added
     * MemberRole case, which is why the named test "keeps the role rule in step with MemberRole"
     * exists and why that test's own comment names the drift rather than the assertion.
     *
     * `owner` is an accepted value, and that is spec §9.3's decision rather than an oversight:
     * ownership transfer happens through this route with `role: owner`, because spec §8's route
     * list is the published surface and `role` is already the field this route changes. The use
     * case is what turns it into the four writes §9.3 requires.
     *
     * No authorize() method. One that returned anything but the literal `true` would make
     * Scramble publish a 403 for this operation, and the authorization here is not a property of
     * the request - it needs the Plan, the actor's membership and the target's role, all of which
     * are resolved in the Application layer (spec §4.3).
     *
     * This docblock is on rules() because a rules() METHOD docblock is harvested by nothing. A
     * comment immediately above the key below would be published as that field's client-facing
     * description, and a class docblock here would be published as
     * components.schemas.ChangeMemberRoleRequest.description.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'role' => ['required', 'string', 'in:owner,admin,member'],
        ];
    }
}
