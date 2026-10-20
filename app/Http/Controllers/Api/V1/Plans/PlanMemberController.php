<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\ChangeMemberRole;
use App\Application\Plans\ListPlanMembers;
use App\Domain\Plans\MemberRole;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\ChangeMemberRoleRequest;
use App\Http\Requests\Api\V1\Plans\ListPlanMembersRequest;
use App\Http\Resources\Api\V1\Plans\PlanMemberResource;
use Dedoc\Scramble\Attributes\Response as ResponseDoc;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class PlanMemberController
{
    use RequiresAuthenticatedUser;

    // A plain comment, not a docblock: a controller method docblock is harvested by Scramble
    // into the operation's public `summary` (MeController@show records the measurement) and
    // the prose allowlist in tests/Feature/Contract/OpenApiContractTest.php fails by name on
    // a new one.
    //
    // No `Plan $plan` type hint, and $plan is a raw string: SubstituteBindings is in the `api`
    // group and sorts before the controller, so an implicit binding would make a missing Plan
    // throw ModelNotFoundException and emit Laravel's own pretty-printed 404 - the second body
    // for one route that §5.1 exists to prevent.
    //
    // requireAuthenticatedUser() rather than a local null check, so this file names no model
    // class: the arch rule "controllers touch no Eloquent and no persistence" fails on the
    // bare import alone, measured by probe.
    //
    // $request->pageQuery() rather than reading limit/cursor here: ListPlanMembersRequest
    // already carries ReadsPageQuery, and the default (PageQuery::DEFAULT_LIMIT) and the
    // cursor decode (PageCursor::fromString(), already guarded by the ValidCursor rule) both
    // live in exactly that one place, so a second copy of either does not belong in this file.
    //
    // The attribute class is imported as ResponseDoc, which is
    // ForgotPasswordController.php's measured form and its stated reason: a bodyless DELETE
    // action this controller will also carry (Task C) types its return as
    // Illuminate\Http\Response, and PHP cannot resolve both that class and
    // Dedoc\Scramble\Attributes\Response to the bare name `Response` in one file - it is a
    // fatal, "Cannot use Illuminate\Http\Response as Response because the name is already in
    // use", and the file then never loads. The alias is written here, in the first action, so
    // the import never has to be rewritten when the DELETE lands.
    #[ResponseDoc(status: 404, description: 'Either no Plan with this id exists, or the signed-in user is not an active member of it. The two answers are deliberately identical.', type: 'array{message: string}')]
    public function index(ListPlanMembersRequest $request, string $plan, ListPlanMembers $listPlanMembers): AnonymousResourceCollection
    {
        $actor = $this->requireAuthenticatedUser($request->user(), 'GET /plans/{plan}/members');

        $page = $listPlanMembers->execute($actor->id, $plan, $request->pageQuery());

        // Not a bare `$page->nextCursor`: Scramble's ClassAnalyzer wraps every non-static
        // property of any analysed class - including CursorPage, the shared page type for
        // every list in this wave - in its own unresolved per-property template type, and
        // handing that straight to additional() fails `make openapi` with
        // "[SE001] Schema UnknownType is not allowed" under --fail-on-unknown. No generic
        // argument, a declared accessor on CursorPage, and removing CursorPage's own
        // @template tag entirely were each measured and each fail identically, so the fix is
        // confined to this read site rather than to the shared DTO. The
        // ternary forces a fresh, concrete type per branch - a literal null, and a `(string)`
        // cast whose result type does not depend on the unresolved template of its operand.
        // The cast is load-bearing: the ternary without it fails identically, and without
        // either the document would publish next_cursor as a bare {"type":"string"}, losing
        // nullability from the contract instead of refusing to generate it. ListPlansController
        // carries the identical device for the same reason.
        return PlanMemberResource::collection($page->items)
            ->additional(['meta' => ['next_cursor' => $page->nextCursor === null ? null : (string) $page->nextCursor]]);
    }

    // Spec §8.2. The job is exactly: read the string off the request, wrap it in the domain
    // type, call one use-case method, wrap the result in a Resource.
    //
    // MemberRole::from() and not tryFrom(): the FormRequest's 'in:owner,admin,member' rule has
    // already refused every other value with a 422, so a value that reaches here and is not a
    // case means the rule and the enum have drifted apart - a ValueError naming this line is the
    // right answer to that, and the test "keeps the role rule in step with MemberRole" is what
    // stops it happening.
    //
    // $plan and $user are raw strings, for the reason index() gives above: no model type hint,
    // because SubstituteBindings sorts before the controller and a binding would answer a
    // missing row with Laravel's own pretty-printed 404 - a second body for a route whose whole
    // design is that its refusals are indistinguishable (spec §5.1).
    //
    // All three statuses are AUTHORED, and the 403 is authored even though Scramble infers one
    // on its own. Measured by deleting all three attributes and regenerating: the row becomes
    // 200, 401, 422 and a 403 published as $ref #/components/responses/AuthorizationException -
    // Scramble's call-graph propagation picks up ChangeMemberRole::execute()'s
    // @throws AuthorizationException - with no 404 and no 409 at all, because a render()-mapped
    // RuntimeException is invisible to it. A $ref response object carries neither its own
    // description nor a message body schema, which is exactly what
    // "declares every error status the application itself raises" checks for every row of its
    // table, so the inferred 403 would publish the status while making its row uncheckable.
    // UpdatePlanController authors its 403 for the same reason on the same shape.
    #[ResponseDoc(status: 403, description: 'The signed-in user is a member of this Plan but may not make this change: only the owner can change a member role, the owner role itself is never edited directly, and the signed-in user must have verified their email address. The body says which.', type: 'array{message: string}')]
    #[ResponseDoc(status: 404, description: 'Either the Plan is not visible to the signed-in user, or the named user is not an active member of it. Both are 404 so that a non-member cannot learn whether the Plan exists; the body says which.', type: 'array{message: string}')]
    #[ResponseDoc(status: 409, description: 'The Plan is archived. An archived Plan is read-only: its members and their roles cannot be changed.', type: 'array{message: string}')]
    public function update(ChangeMemberRoleRequest $request, string $plan, string $user, ChangeMemberRole $changeMemberRole): PlanMemberResource
    {
        $actor = $this->requireAuthenticatedUser($request->user(), 'PATCH /plans/{plan}/members/{user}');

        return PlanMemberResource::make($changeMemberRole->execute(
            $actor->id,
            $plan,
            $user,
            MemberRole::from($request->string('role')->toString()),
        ));
    }
}
