<?php

declare(strict_types=1);

use App\Application\Plans\PageQuery;
use App\Domain\Plans\PlanStatus;
use App\Http\Requests\Api\V1\Plans\ListPlansRequest;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

// Not in the brief's own literal signature: a bare `array $query` with no generic value type
// trips PHPStan level 8's missingType.iterableValue, measured against this exact function
// before the @param tag below was added.
/** @param array<string, mixed> $query */
function validatedListPlansRequest(array $query): ListPlansRequest
{
    $request = ListPlansRequest::create('/api/v1/plans', 'GET', $query);
    $validator = Validator::make($query, (new ListPlansRequest)->rules());
    $request->setValidator($validator);

    // Not $request->validator->validate() (the brief's literal form): $validator is a
    // protected property on FormRequest, and reading it as $request->validator from this
    // global test function - outside the class hierarchy - does not return it. PHP routes an
    // inaccessible property read through Illuminate\Http\Request::__get(), which this class
    // inherits and which does not know about $validator at all: it falls through to
    // Arr::get($this->all(), 'validator', fn () => $this->route('validator')), and both
    // branches miss, so the read silently returns null. Measured by probe: var_dump($request
    // ->validator) prints NULL immediately after setValidator() runs. $request->validator
    // ->validate() therefore throws "Call to a member function validate() on null" for every
    // call, on every row - the brief's own prescribed helper does not survive contact with
    // PHP's property-visibility rules, independent of anything in ListPlansRequest.php.
    // Validating the local $validator (an Illuminate\Validation\Validator, not a FormRequest)
    // is the fix: its own validate() throws ValidationException directly on failure
    // (Illuminate\Validation\Validator::validate(), which is `if ($this->fails()) throw …;`)
    // with no FormRequest, container or redirector involved - the exact mechanism the brief
    // was reaching for, reached without the broken property hop.
    $validator->validate();

    return $request;
}

it('defaults limit to 25 and status to active when the query string is empty', function (): void {
    $request = validatedListPlansRequest([]);

    expect($request->pageQuery())->toEqual(new PageQuery(PageQuery::DEFAULT_LIMIT, null));
    expect($request->status())->toBe(PlanStatus::Active);
});

it('accepts cancelled even though nothing in Wave 2 writes it', function (): void {
    // Spec §6.3 / §7.3: the filter is honest about the column's full domain, not about what
    // is reachable today.
    expect(validatedListPlansRequest(['status' => 'cancelled'])->status())->toBe(PlanStatus::Cancelled);
});

it('refuses a status outside the three the column can hold', function (): void {
    expect(fn () => validatedListPlansRequest(['status' => 'deleted']))
        ->toThrow(ValidationException::class);
});

it('refuses limit 101, a 422 rather than a silent clamp (D-R6)', function (): void {
    expect(fn () => validatedListPlansRequest(['limit' => '101']))
        ->toThrow(ValidationException::class);
});

// Amendment 7 / residual 19: this file had an absent->25 case and an over-cap case for limit,
// but its only present-null case (above) is for status, not limit - so a present-but-null
// ?limit= had no case of its own. Per H9, ['limit' => null] IS the shape a real `?limit=`
// delivers after TrimStrings + ConvertEmptyStringsToNull - present-and-null, not whitespace -
// so this does not need a separate whitespace case the way status does.
it('refuses a present-but-null limit with a 422, not DEFAULT_LIMIT (Amendment 7)', function (): void {
    // 'integer' is present and 'nullable' is deliberately absent on limit's rule (unlike
    // status): validateInteger(null) is false, so a present null 422s here rather than sailing
    // through to intval(null) === 0. ReadsPageQuery's own docblock names this as the opposite
    // choice from status, deliberately.
    expect(fn () => validatedListPlansRequest(['limit' => null]))
        ->toThrow(ValidationException::class);
});

// Residual 20: min:1 was deletable from rules() with the whole suite green, because the only
// case that reads as coverage for limit=0 - see PlanRepositoryTest's "clamps a limit of 0" -
// constructs PageQuery(0, null) directly and never calls rules() at all. This is the missing
// half: a caller going through rules() with limit=0 must 422 here, not reach the repository as
// an unclamped 0. Attributable to min:1 alone: 'integer' accepts '0' and 'max:100' accepts it
// too, so only min:1 rejects this value.
it('refuses limit 0 through rules(), not only through the repository clamp (residual 20)', function (): void {
    expect(fn () => validatedListPlansRequest(['limit' => '0']))
        ->toThrow(ValidationException::class);
});

// The two tests below are not in Task B's own brief. The context file controlling this task
// names a live, measured trap landing squarely on ListPlansRequest: Illuminate\Foundation\Http\
// Middleware\ConvertEmptyStringsToNull sits in Laravel's default global middleware stack and
// bootstrap/app.php never removes it, so `?status=` arrives through the real HTTP kernel as a
// PRESENT null - $request->query->all() === ['status' => null] - never an absent key and never
// an empty string. That is exactly the shape validatedListPlansRequest(['status' => null])
// constructs: $query is handed to BOTH ListPlansRequest::create() (as the GET parameters) and
// Validator::make() (as the data to validate), so passing a present null key here reproduces
// what the middleware delivers without needing the middleware itself.
//
// Per the context file's H1 corollary ("an expectation satisfiable by either of two clauses
// tests neither of them"), the validation-rule guard and the status()-defaulting guard are two
// DIFFERENT clauses that a single assertion could pass for the wrong reason - e.g. a status()
// that happens to catch a ValidationException and silently returns Active would make a single
// "does not throw and returns Active" assertion pass even with broken validation. So each gets
// its own test, aimed at its own clause, with the other clause's input left unexceptional.
it('does not reject a present-but-null status query parameter with a 422', function (): void {
    // Isolates the validation-rule clause only: Validator::make() directly, no status() call.
    // ['sometimes', 'string', Rule::in(...)] alone - the brief's own prescribed rule, with no
    // 'nullable' - measured to FAIL this with "The status field must be a string.": Laravel's
    // own validateString() is `is_string($value)`, and is_string(null) is false. 'nullable'
    // is what makes isNotNullIfMarkedAsNullable() skip the 'string' and 'Rule::in' checks for
    // a null value (Illuminate\Validation\Validator::isValidatable()).
    $validator = Validator::make(['status' => null], (new ListPlansRequest)->rules());

    expect($validator->fails())->toBeFalse('a present null status must validate, matching what ConvertEmptyStringsToNull actually delivers for ?status=');
});

it('defaults status to active for a present-but-null status query parameter, not only an absent key', function (): void {
    // Isolates the status()-defaulting clause only, given that the line above already proves
    // validation does not block it. Request::input('status', <default>)'s own default
    // argument is ignored whenever the key is present at all - data_get()'s Arr::exists()
    // branch returns the stored value (null) without ever consulting $default - so a status()
    // built on $this->string('status', PlanStatus::Active->value) would see
    // Stringable((string) null) === Stringable('') here and throw an uncaught ValueError from
    // PlanStatus::from(''), not default to Active. Measured by writing that exact form and
    // watching this assertion fail with "" is not a valid backing value for enum
    // App\Domain\Plans\PlanStatus before being restored.
    expect(validatedListPlansRequest(['status' => null])->status())
        ->toBe(PlanStatus::Active, 'a present null ?status= must default the same way an absent key does');
});

// T11-1 (fix round 1): the present-null fix above does not cover a whitespace-only value, which
// is a DIFFERENT shape surviving a DIFFERENT exact-match gap. ConvertEmptyStringsToNull's own
// transform() is `$value === '' ? null : $value` - exact match only - so `?status=%20` decodes
// to the single-character string ' ', which is not '' and so is never turned into null, and a
// bare `?? PlanStatus::Active->value` in status() would still hand it straight to
// PlanStatus::from(), since ' ' !== null. The two present-null tests above cannot exercise this
// shape at all - their fixture is the value null, not the string ' ' - so it needs its own pair
// of tests, aimed at the same two clauses, per the same H1 corollary the present-null pair
// already follows.
it('does not reject a whitespace-only status query parameter with a 422', function (): void {
    // Isolates the validation-rule clause only. Illuminate\Validation\Validator::
    // presentOrRuleIsImplicit() has its own exact-match-adjacent special case: `if
    // (is_string($value) && trim($value) === '') return $this->isImplicit($rule);` - and
    // 'nullable', 'string' and Rule::in() are all non-implicit, so EVERY rule on the field is
    // skipped for a whitespace-only value, independent of whether 'nullable' is present at
    // all. Rules()'s own docblock names this boundary; this test is what pins it as behaviour.
    $validator = Validator::make(['status' => ' '], (new ListPlansRequest)->rules());

    expect($validator->fails())->toBeFalse('a whitespace-only status must validate - every non-implicit rule on this field is skipped for that shape, independent of nullable');
});

it('defaults status to active for a whitespace-only status query parameter', function (): void {
    // Isolates the status()-defaulting clause only, given the line above already proves
    // validation does not block it. $this->input('status') ?? PlanStatus::Active->value alone
    // - the fix for the present-null case above - does NOT catch this shape: ' ' is not null,
    // so that form would hand ' ' straight to PlanStatus::from(), throwing an uncaught
    // ValueError identical in kind to the present-null one, one shape over. Measured by writing
    // that exact ??-only form and watching this assertion error with " " is not a valid backing
    // value for enum App\Domain\Plans\PlanStatus, before being restored to the trim()-based
    // form that treats a whitespace-only value the same as an absent key or a present null.
    expect(validatedListPlansRequest(['status' => ' '])->status())
        ->toBe(PlanStatus::Active, 'a whitespace-only ?status= must default the same way a present null and an absent key do');
});
