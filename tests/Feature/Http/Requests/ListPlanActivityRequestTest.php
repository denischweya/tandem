<?php

declare(strict_types=1);

use App\Application\Plans\PageQuery;
use App\Http\Requests\Api\V1\Plans\ListPlanActivityRequest;
use Illuminate\Support\Facades\Validator;

// Not $request->validator->validate() (the brief's literal form): $validator is a protected
// property on FormRequest, and reading it as $request->validator from this global test function
// - outside the class hierarchy - does not return it. PHP routes an inaccessible property read
// through Illuminate\Http\Request::__get(), which this class inherits and which does not know
// about $validator at all, so the read silently returns null. Measured by probe (see
// ListPlansRequestTest::validatedListPlansRequest()'s own docblock, which measured the
// identical shape first, and ListPlanMembersRequestTest's copy of it): var_dump($request
// ->validator) prints NULL immediately after setValidator() runs, and ->validate() on that null
// throws "Call to a member function validate() on null" for every call. Validating the local
// $validator (an Illuminate\Validation\Validator, not a FormRequest) is the fix, copied from
// that shipped precedent rather than reinvented, per the context file's blocking item 1a.
// setValidator() is still called and still correct - only the assertion moves to the local.
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
