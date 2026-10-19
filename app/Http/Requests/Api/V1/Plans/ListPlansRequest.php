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
     * an absent key. Without 'nullable', Illuminate\Validation\Validator::isValidatable()
     * still runs the 'string' rule against that null (presentOrRuleIsImplicit() treats a
     * present key, of any value, as present), and validateString() is bare is_string($value) -
     * false for null - so the request would 422 on `?status=` where the spec wants the
     * default. 'nullable' makes isNotNullIfMarkedAsNullable() skip 'string' and the Rule::in()
     * check for a null value, so the field reaches status() below unvalidated-against but not
     * rejected. limit and cursor keep no 'nullable' deliberately - ReadsPageQuery's own
     * docblock documents why a present-null limit SHOULD 422 rather than silently take
     * DEFAULT_LIMIT, which is the opposite choice from status for a reason stated once, there.
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
            'status' => ['sometimes', 'nullable', 'string', Rule::in(['active', 'archived', 'cancelled'])],
        ];
    }

    /**
     * Not $this->string('status', PlanStatus::Active->value)->toString(): Request::input()'s
     * $default argument is consulted only when the key is wholly ABSENT -
     * data_get()'s Arr::exists() branch returns the stored value, unconditionally, whenever
     * the key exists at all - so for a present null that form resolves to
     * Stringable((string) null) === Stringable(''), and PlanStatus::from('') throws an
     * uncaught ValueError instead of defaulting. Measured directly against this method before
     * this docblock was written; see ListPlansRequestTest's two present-null cases, which this
     * form passes and the string()-based form does not.
     *
     * $this->input('status') resolves to null for BOTH an absent key and a present null, which
     * is exactly the one-?? shape that treats them identically - the same default, applied in
     * one place, that D-R6 requires of limit.
     */
    public function status(): PlanStatus
    {
        return PlanStatus::from($this->input('status') ?? PlanStatus::Active->value);
    }
}
