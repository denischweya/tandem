<?php

declare(strict_types=1);

use App\Application\Plans\PageQuery;
use App\Http\Requests\Api\V1\Plans\ListPlanMembersRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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

// Amendment 7 / residual 19: this file had only the absent->25 case above - no present-null and
// no over-cap case for limit, so `max:'.PageQuery::MAX_LIMIT` was deletable from rules() with
// the whole suite green. The three cases below close that, mirroring ListPlansRequestTest's own
// (which already had all three before this task).
it('refuses a present-but-null limit with a 422, not DEFAULT_LIMIT (Amendment 7)', function (): void {
    // ['limit' => null] is the real shape a present null ?limit= delivers after TrimStrings +
    // ConvertEmptyStringsToNull (H9) - present-and-null, not whitespace. 'integer' is present
    // and 'nullable' is deliberately absent, so validateInteger(null) 422s here rather than
    // sailing through to intval(null) === 0.
    expect(fn () => validatedListPlanMembersRequest(['limit' => null]))
        ->toThrow(ValidationException::class);
});

it('refuses limit 101, a 422 rather than a silent clamp', function (): void {
    expect(fn () => validatedListPlanMembersRequest(['limit' => '101']))
        ->toThrow(ValidationException::class);
});

// Residual 20: min:1 was deletable from rules() with the whole suite green, because the only
// case that reads as coverage for limit=0 - see PlanMemberRepositoryTest's "clamps a limit of
// 0" - constructs PageQuery(0, null) directly and never calls rules() at all. Attributable to
// min:1 alone: 'integer' accepts '0' and 'max:'.PageQuery::MAX_LIMIT accepts it too, so only
// min:1 rejects this value.
it('refuses limit 0 through rules(), not only through the repository clamp (residual 20)', function (): void {
    expect(fn () => validatedListPlanMembersRequest(['limit' => '0']))
        ->toThrow(ValidationException::class);
});
