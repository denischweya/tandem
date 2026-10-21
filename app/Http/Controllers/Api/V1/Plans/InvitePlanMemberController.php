<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\InvitePlanMember;
use App\Domain\Plans\MemberRole;
use App\Domain\Users\Email;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\InvitePlanMemberRequest;
use App\Http\Resources\Api\V1\Plans\PlanInviteCreatedResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

final class InvitePlanMemberController
{
    use RequiresAuthenticatedUser;

    // Four declared statuses, because Scramble infers a 422 from a FormRequest and a 401 from
    // auth middleware and reads neither an exception-render closure nor throttle: middleware. The
    // 403 is NOT inferred here either, because this codebase's controllers extend nothing, so
    // $this->authorize() - the one form Scramble's PossibleExceptionInfer catches - is
    // unavailable; the refusal is an AuthorizationException thrown from the Application layer.
    //
    // {plan} is a raw string, never a type-hinted Plan: SubstituteBindings sorts AFTER
    // ThrottleRequests and before the controller, so an implicit binding would make a missing
    // Plan throw ModelNotFoundException and emit Laravel's own PRETTY-PRINTED 404 body,
    // reintroducing exactly the two-bodies-for-one-route problem §5.1 rejects whereUuid to avoid.
    #[Response(status: 403, description: 'The signed-in member may not invite people to this Plan, or may not invite them with the role requested. Inviting requires Owner or Admin; only an Owner may invite an Admin.', type: 'array{message: string}')]
    #[Response(status: 404, description: 'No Plan with this id is visible to the signed-in user. The same body is returned for a Plan that does not exist and for one the user is not an active member of.', type: 'array{message: string}')]
    #[Response(status: 409, description: 'The Plan cannot be invited to: it has been archived, or its visibility is private. The message distinguishes the two.', type: 'array{message: string}')]
    #[Response(status: 429, description: 'Too many invites: 50 per day per user, and 20 per hour per Plan. Carries Retry-After.', type: 'array{message: string}')]
    public function __invoke(
        InvitePlanMemberRequest $request,
        string $plan,
        InvitePlanMember $invitePlanMember,
    ): JsonResponse {
        $user = $this->requireAuthenticatedUser($request->user(), 'POST /plans/{plan}/invites');

        // '' means the field was absent: Laravel's global ConvertEmptyStringsToNull turns a
        // submitted empty string into null, which `sometimes|required` already rejected with a
        // 422, so the only way to reach here with '' is an absent key - a link-only invite.
        $email = $request->string('email')->toString();

        // from(), not tryFrom(): the in: rule has already run, so a value this cannot resolve
        // means the rule and the enum disagree, and that is a programming error to hear about
        // rather than to paper over with a silent default.
        $role = MemberRole::from($request->string('role', MemberRole::Member->value)->toString());

        $issued = $invitePlanMember->execute(
            $user,
            $plan,
            $email === '' ? null : Email::fromString($email),
            $role,
        );

        // 201 spelled the way RegisterController spells it.
        return PlanInviteCreatedResource::make($issued)->response()->setStatusCode(201);
    }
}
