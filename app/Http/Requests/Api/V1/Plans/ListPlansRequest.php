<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Plans;

use App\Application\Plans\PageQuery;
use App\Domain\Plans\PlanStatus;
use App\Http\Requests\Concerns\ReadsPageQuery;
use App\Http\Rules\ValidCursor;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class ListPlansRequest extends FormRequest
{
    use ReadsPageQuery;

    /**
     * status defaults to active rather than to "every status this actor can see": spec §9.3
     * branch 3 can archive a Plan behind the departing owner's back, so a member's default
     * list silently gaining an archived Plan through nobody's action is the failure this
     * default exists to prevent. cancelled validates even though nothing in Wave 2 writes it
     * (§7.3) - the filter describes the column's domain, not what is reachable today.
     *
     * 'nullable' on status, unlike limit and cursor above it: Illuminate\Foundation\Http\
     * Middleware\ConvertEmptyStringsToNull is in Laravel's default global middleware stack and
     * bootstrap/app.php never removes it, so a real `?status=` arrives as a PRESENT null, not
     * an absent key.
     *
     * CORRECTED: an earlier version of this docblock claimed a whitespace-only `?status=%20`
     * is a SECOND blank shape, surviving ConvertEmptyStringsToNull as the literal string ' '
     * because that middleware's transform() is an exact match (`$value === '' ? null :
     * $value`). Measured false for any real request: TrimStrings runs BEFORE
     * ConvertEmptyStringsToNull in Laravel 13's default global stack, and bootstrap/app.php
     * never reorders it. TrimStrings turns `' '` into `''` first, and ConvertEmptyStringsToNull
     * then turns that `''` into `null` - so `?status=%20` arrives exactly like `?status=`: a
     * PRESENT null. There is one blank shape at the validator, not two.
     *
     * The real boundary Illuminate\Validation\Validator::presentOrRuleIsImplicit() applies is
     * narrower than "any present key is present": `if (is_string($value) && trim($value) ===
     * '') return $this->isImplicit($rule);` - so a present key counts as present UNLESS its
     * value is a string that trim()s to empty, in which case every NON-IMPLICIT rule on the
     * field is skipped outright, 'nullable' included. That branch is therefore dead for any
     * value a real HTTP request can deliver on this field - null is not a string, so the
     * short-circuit never fires for it - and 'string' (non-implicit) runs against it for real:
     * validateString() is bare is_string($value), false for null, so without 'nullable' the
     * request would 422 on a present null `?status=` where the spec wants the default.
     * 'nullable' is what makes isNotNullIfMarkedAsNullable() skip 'string' and Rule::in() for
     * that null, and it is load-bearing for every blank shape HTTP can deliver on this field,
     * not merely one of two. status() below still carries an is_string($status) &&
     * trim($status) !== '' guard - belt-and-braces for HTTP, where that branch cannot fire, but
     * load-bearing for a non-HTTP caller that builds this FormRequest (or calls status()
     * directly) without going through the global middleware. limit and cursor keep no
     * 'nullable' deliberately - ReadsPageQuery's own docblock documents why a present-null limit
     * SHOULD 422 rather than silently take DEFAULT_LIMIT, which is the opposite choice from
     * status for a reason stated once, there.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            /** @query */
            'limit' => ['sometimes', 'integer', 'min:1', 'max:'.PageQuery::MAX_LIMIT],
            /** @query */
            'cursor' => ['sometimes', 'string', new ValidCursor],
            /** @query */
            'status' => ['sometimes', 'nullable', 'string', Rule::in(array_column(PlanStatus::cases(), 'value'))],
        ];
    }

    /**
     * Not $this->string('status', PlanStatus::Active->value)->toString(): Request::input()'s
     * $default argument is consulted only when the key is wholly ABSENT -
     * data_get()'s Arr::exists() branch returns the stored value, unconditionally, whenever
     * the key exists at all - so for a present null that form resolves to
     * Stringable((string) null) === Stringable(''), and PlanStatus::from('') throws an
     * uncaught ValueError instead of defaulting. Measured directly against this method before
     * this docblock was written; see ListPlansRequestTest's present-null defaulting case, which
     * this form passes and the string()-based form does not (the validation-isolating
     * present-null case never calls status() at all, so it cannot distinguish the two forms).
     *
     * Not a bare `$this->input('status') ?? PlanStatus::Active->value` either: for a real
     * `?status=%20`, TrimStrings runs before ConvertEmptyStringsToNull (see rules()'s own
     * docblock, corrected) and the value already arrives here as null, same as `?status=` - so
     * a bare `??` would in fact catch it. The is_string($status) && trim($status) !== '' form
     * is kept anyway: it is belt-and-braces for that HTTP shape, but load-bearing for a
     * non-HTTP caller that passes the literal string ' ' directly (a use case invoked without
     * going through the global middleware, or a test that calls status() directly) - without
     * it, such a caller's whitespace-only value would reach PlanStatus::from(' ') and throw an
     * uncaught ValueError instead of defaulting.
     */
    public function status(): PlanStatus
    {
        $status = $this->input('status');

        return PlanStatus::from(is_string($status) && trim($status) !== '' ? $status : PlanStatus::Active->value);
    }
}
