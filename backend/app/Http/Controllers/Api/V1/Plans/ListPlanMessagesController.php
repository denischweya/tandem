<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1\Plans;

use App\Application\Plans\ListPlanMessages;
use App\Http\Controllers\Concerns\RequiresAuthenticatedUser;
use App\Http\Requests\Api\V1\Plans\ListPlanMessagesRequest;
use App\Http\Resources\Api\V1\Plans\PlanMessageResource;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

final class ListPlanMessagesController
{
    use RequiresAuthenticatedUser;

    // One declared status: the 404 Scramble cannot see (bootstrap/app.php's exception-render
    // closure), matching PlanActivityController's own comment. The 401 and the 422 are inferred
    // (auth:sanctum, and ListPlanMessagesRequest's rules).
    #[Response(status: 404, description: 'No Plan with this id is visible to the signed-in user. A Plan that does not exist and a Plan the user is not an active member of answer identically.', type: 'array{message: string}')]
    public function __invoke(
        ListPlanMessagesRequest $request,
        string $plan,
        ListPlanMessages $listPlanMessages,
    ): AnonymousResourceCollection {
        $user = $this->requireAuthenticatedUser($request->user(), 'GET /plans/{plan}/messages');

        $page = $listPlanMessages->execute($user, $plan, $request->pageQuery());

        // Not a bare `$page->nextCursor` - see PlanActivityController's own comment for the
        // measured Scramble/PHPStan reason: CursorPage's unresolved per-property template type
        // fails `make openapi` with "[SE001] Schema UnknownType is not allowed" under
        // --fail-on-unknown unless the read site forces a fresh, concrete type per ternary branch.
        return PlanMessageResource::collection($page->items)
            ->additional(['meta' => ['next_cursor' => $page->nextCursor === null ? null : (string) $page->nextCursor]]);
    }
}
