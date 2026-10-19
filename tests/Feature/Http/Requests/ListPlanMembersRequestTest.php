<?php

declare(strict_types=1);

use App\Application\Plans\PageQuery;
use App\Http\Requests\Api\V1\Plans\ListPlanMembersRequest;
use Illuminate\Support\Facades\Validator;

// Not $request->validator->validate() (the brief's literal form): $validator is a protected
// property on FormRequest, and reading it as $request->validator from this global test function
// - outside the class hierarchy - does not return it. PHP routes an inaccessible property read
// through Illuminate\Http\Request::__get(), which this class inherits and which does not know
// about $validator at all, so the read silently returns null. Measured by probe (see
// ListPlansRequestTest::validatedListPlansRequest()'s own docblock, which measured the
// identical shape first): var_dump($request->validator) prints NULL immediately after
// setValidator() runs, and ->validate() on that null throws "Call to a member function
// validate() on null" for every call. Validating the local $validator (an
// Illuminate\Validation\Validator, not a FormRequest) is the fix, copied from that shipped
// precedent rather than reinvented, per the context file's blocking item 1. setValidator() is
// still called and still correct - the request must carry its validator - only the assertion
// moves to the local.
/** @param array<string, mixed> $query */
function validatedListPlanMembersRequest(array $query): ListPlanMembersRequest
{
    $request = ListPlanMembersRequest::create('/api/v1/plans/x/members', 'GET', $query);
    $validator = Validator::make($query, (new ListPlanMembersRequest)->rules());
    $request->setValidator($validator);
    $validator->validate();

    return $request;
}

it('defaults limit to 25 and cursor to null', function (): void {
    expect(validatedListPlanMembersRequest([])->pageQuery())
        ->toEqual(new PageQuery(PageQuery::DEFAULT_LIMIT, null));
});

it('refuses a malformed cursor as a 422, not a 500', function (): void {
    // Not validatedListPlanMembersRequest(): that helper's $validator->validate() THROWS on
    // failure (Illuminate\Validation\Validator::validate() is `if ($this->fails()) throw ...`),
    // which would stop this test before it could inspect fails()/errors() below. Built directly
    // instead - same local-variable fix as the helper, applied inline since nothing here reads
    // $request->pageQuery().
    $request = ListPlanMembersRequest::create('/api/v1/plans/x/members', 'GET', ['cursor' => 'not-a-cursor']);
    $validator = Validator::make(['cursor' => 'not-a-cursor'], (new ListPlanMembersRequest)->rules());
    $request->setValidator($validator);

    expect($validator->fails())->toBeTrue();
    expect($validator->errors()->first('cursor'))->toBe('The cursor is not a valid page cursor.');
});
