<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Plans;

use App\Http\Rules\IanaTimeZone;
use Illuminate\Foundation\Http\FormRequest;

final class CreatePlanRequest extends FormRequest
{
    /**
     * Three fields, and the four omissions are each a decision.
     *
     * `group_id` is absent, and that absence IS the whole of what Wave 2 owes Groups (§12.2).
     * Not a `prohibited` rule: `prohibited` would publish a group_id field in the contract and
     * document a capability the API does not have. Not a defensive check in the use case
     * either - Plan is $guarded = ['*'], so an array reaching fill() throws rather than setting
     * anything. The request shape is the contract, the model is the backstop, and a third guard
     * would be a third place to keep in step.
     *
     * `cover` is absent because there is no upload path (spec §15 defers attachments), so
     * plans.cover_image_path stays a column with no writer. `initial date` / `initial event`
     * are plan_events concepts and plan_events is not in Cycle 1's table list. `member_ids` is
     * absent because adding people by id bypasses the only consented join path there is -
     * MemberStatus has no "added without being asked" case, and §10.7's acceptance is where
     * consent is recorded in accepted_by.
     *
     * `timezone` is required rather than defaulted from user_profiles.timezone: a server-side
     * default makes a Plan's timezone a silent snapshot of whatever the creator's profile said
     * at that instant, with no way for a client to say "this Plan is in Nairobi although I am
     * in London". The client already holds the value from GET /me.
     *
     * This docblock is on rules() because a rules() METHOD docblock is harvested by nothing.
     * The two positions in this class that ARE published verbatim into openapi.json are the
     * class docblock (-> components.schemas.CreatePlanRequest.description) and a comment
     * immediately above a rules() array key (-> that field's client-facing description). The
     * one line below that sits in the second position is deliberate and client-facing, and it
     * is listed by name in OpenApiContractTest's published-prose allow-list.
     *
     * No authorize(): Scramble publishes a 403 for any FormRequest whose authorize() return
     * type is not the literal `true`, which is how POST /auth/verify-email acquired a 403 that
     * appears in no literal table. Authorization for this route is the `verified` middleware,
     * which Scramble cannot see and which the controller declares with a #[Response] attribute.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'title' => ['required', 'string', 'max:255'],
            'description' => ['sometimes', 'required', 'string', 'max:2000'],
            /** An IANA time zone identifier, such as "Europe/London" or "America/New_York". */
            'timezone' => ['required', 'string', new IanaTimeZone],
        ];
    }
}
