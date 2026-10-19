<?php

declare(strict_types=1);

use App\Application\Plans\PageQuery;
use App\Http\Requests\Api\V1\Plans\ListPlanActivityRequest;
use Illuminate\Support\Facades\Validator;

// Same fix as ListPlansRequestTest::validatedListPlansRequest() and ListPlanMembersRequestTest's
// copy of it, both of which carry the full measurement: $validator is a protected property
// Illuminate\Http\Request::__get() does not expose, so $request->validator reads NULL and
// ->validate() on it throws. Validate the local $validator instead; setValidator() is still
// called, since the request still has to carry it.
/** @param array<string, mixed> $query */
function validatedListPlanActivityRequest(array $query): ListPlanActivityRequest
{
    $request = ListPlanActivityRequest::create('/api/v1/plans/x/activity', 'GET', $query);
    $validator = Validator::make($query, (new ListPlanActivityRequest)->rules());
    $request->setValidator($validator);
    $validator->validate();

    return $request;
}

it('defaults limit to 25 and cursor to null', function (): void {
    expect(validatedListPlanActivityRequest([])->pageQuery())
        ->toEqual(new PageQuery(PageQuery::DEFAULT_LIMIT, null));
});

it('refuses a malformed cursor as a 422, not a 500', function (): void {
    // The brief's own single case passes [], which exercises neither rule() entry: deleting
    // `new ValidCursor` from cursor's rule, or mangling limit's `max:`, both ship this file
    // green with no other change. This case is ListPlanMembersRequestTest's own second case,
    // copied, and it is what the round-1 break step below actually reddens.
    //
    // Not validatedListPlanActivityRequest(): that helper's $validator->validate() THROWS on
    // failure (Illuminate\Validation\Validator::validate() is `if ($this->fails()) throw ...`),
    // which would stop this test before it could inspect fails()/errors() below. Built directly
    // instead - same local-variable fix as the helper, applied inline since nothing here reads
    // $request->pageQuery().
    $request = ListPlanActivityRequest::create('/api/v1/plans/x/activity', 'GET', ['cursor' => 'not-a-cursor']);
    $validator = Validator::make(['cursor' => 'not-a-cursor'], (new ListPlanActivityRequest)->rules());
    $request->setValidator($validator);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('cursor'))->toBe('The cursor is not a valid page cursor.');
});
