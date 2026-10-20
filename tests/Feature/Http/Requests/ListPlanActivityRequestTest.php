<?php

declare(strict_types=1);

use App\Application\Plans\PageQuery;
use App\Http\Requests\Api\V1\Plans\ListPlanActivityRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

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

// Amendment 7 / residual 19: this file had only the absent->25 case above - no present-null and
// no over-cap case for limit, so `max:'.PageQuery::MAX_LIMIT` was deletable from rules() with
// the whole suite green. The three cases below close that, mirroring ListPlansRequestTest's own
// (which already had all three before this task).
it('refuses a present-but-null limit with a 422, not DEFAULT_LIMIT (Amendment 7)', function (): void {
    // ['limit' => null] is the real shape a present null ?limit= delivers after TrimStrings +
    // ConvertEmptyStringsToNull (H9) - present-and-null, not whitespace. 'integer' is present
    // and 'nullable' is deliberately absent, so validateInteger(null) 422s here rather than
    // sailing through to intval(null) === 0.
    expect(fn () => validatedListPlanActivityRequest(['limit' => null]))
        ->toThrow(ValidationException::class);
});

it('refuses limit 101, a 422 rather than a silent clamp', function (): void {
    expect(fn () => validatedListPlanActivityRequest(['limit' => '101']))
        ->toThrow(ValidationException::class);
});

// Residual 20: min:1 was deletable from rules() with the whole suite green, because the only
// case that reads as coverage for limit=0 - see PlanActivityRepositoryTest's "clamps a limit of
// 0" - constructs PageQuery(0, null) directly and never calls rules() at all. Attributable to
// min:1 alone: 'integer' accepts '0' and 'max:'.PageQuery::MAX_LIMIT accepts it too, so only
// min:1 rejects this value.
it('refuses limit 0 through rules(), not only through the repository clamp (residual 20)', function (): void {
    expect(fn () => validatedListPlanActivityRequest(['limit' => '0']))
        ->toThrow(ValidationException::class);
});
