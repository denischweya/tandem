<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Plans;

use App\Http\Rules\IanaTimeZone;
use Illuminate\Foundation\Http\FormRequest;

final class UpdatePlanRequest extends FormRequest
{
    /**
     * Three writable fields, each sometimes|required - UpdateProfileRequest's shape. Omitting a
     * key leaves the value unchanged; sending an empty one is a 422, because the GLOBAL
     * ConvertEmptyStringsToNull middleware turns '' into null before any rule runs and `required`
     * then refuses it. A whitespace-only value ('   ') survives that middleware (exact-match
     * only) and would skip every NON-implicit rule under
     * Validator::presentOrRuleIsImplicit() - but `required` is itself one of Laravel's implicit
     * rules, so it always runs, and its own trim($value) === '' check refuses a whitespace-only
     * value the same way it refuses ''. Measured via UpdatePlanTest's dedicated whitespace case,
     * rather than assumed: this is the shape that made an optional field a 500 elsewhere in this
     * wave (an enum `from()` fed by a FormRequest field that had no implicit rule to force it to
     * run), and every field here is safe specifically because `required` is on it. An empty body
     * is valid and writes nothing.
     *
     * Not writable, and each for its own reason: owner_id (ownership moves only through spec
     * §9.3's transfer), status (that is POST /plans/{plan}/archive), visibility (published
     * read-only in Cycle 1 - spec §10 and §11 name no privacy control, so a writable toggle
     * would ship a capability nothing observes), group_id (§12.2, and its absence IS the
     * refusal), cover_image_path (no upload path exists, spec §15).
     *
     * timezone is an `edit Plan` field rather than a `manage Plan settings` one. Blueprint §9
     * splits the two and never says which bucket timezone falls in; reading it as `edit Plan`
     * keeps PATCH to one authorization rule rather than authorizing per field. Revisit when a
     * cycle attaches events to a Plan: at that point changing timezone moves real times for
     * other people, and the owner-only reading becomes the better one.
     *
     * This docblock is on rules() because a rules() METHOD docblock is harvested by nothing. A
     * comment immediately above any key below would be published as that field's client-facing
     * description, and a class docblock on this class would be published as
     * components.schemas.UpdatePlanRequest.description. Neither position carries prose here: the
     * IANA sentence is published once, on CreatePlanRequest.timezone, and the prose allow-list
     * in OpenApiContractTest lists exactly that one.
     *
     * No authorize(): Scramble publishes a 403 for any FormRequest whose authorize() return type
     * is not the literal `true`. The 403 this route really does raise comes from the role check
     * in UpdatePlan and from the `verified` middleware, and the controller declares it with a
     * #[Response] attribute instead.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:2000'],
            'timezone' => ['sometimes', 'required', 'string', new IanaTimeZone],
        ];
    }
}
