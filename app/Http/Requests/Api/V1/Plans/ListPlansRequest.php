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
     * The real boundary Illuminate\Validation\Validator::presentOrRuleIsImplicit() applies is
     * narrower than "any present key is present": `if (is_string($value) && trim($value) ===
     * '') return $this->isImplicit($rule);` - so a present key counts as present UNLESS its
     * value is a string that trim()s to empty, in which case every NON-IMPLICIT rule on the
     * field is skipped outright, 'nullable' included. That split matters here because this
     * field's two blank-ish shapes land on opposite sides of it. null is not a string, so the
     * short-circuit never fires for it, and 'string' (non-implicit) then runs against it for
     * real - validateString() is bare is_string($value), false for null - so without 'nullable'
     * the request would 422 on a present null `?status=` where the spec wants the default;
     * 'nullable' is what makes isNotNullIfMarkedAsNullable() skip 'string' and Rule::in() for
     * that null. A whitespace-only value such as '?status=%20' (ConvertEmptyStringsToNull's own
     * transform() is an EXACT match, `$value === '' ? null : $value`, so a single space is not
     * turned into null and survives as the string ' ') hits the OTHER branch instead: 'string'
     * and Rule::in() are skipped regardless of 'nullable', so this field validates for that
     * shape either way. 'nullable' is therefore load-bearing for the null shape and inert for
     * the whitespace one; status() below is what makes both default correctly despite
     * validation treating the two differently. limit and cursor keep no 'nullable' deliberately
     * - ReadsPageQuery's own docblock documents why a present-null limit SHOULD 422 rather than
     * silently take DEFAULT_LIMIT, which is the opposite choice from status for a reason stated
     * once, there.
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
     * Not a bare `$this->input('status') ?? PlanStatus::Active->value` either: ' ' is not null,
     * so that form still hands a whitespace-only value straight to PlanStatus::from(), which
     * throws the same shape of uncaught ValueError one input over - '?status=%20' survives
     * ConvertEmptyStringsToNull's exact-match transform() as the string ' ' (see rules()'s own
     * docblock). is_string($status) && trim($status) !== '' treats a whitespace-only value the
     * same as an absent key or a present null - a blank string carries no more intent than
     * either - which is also why rules() cannot 422 it: every non-implicit rule on the field,
     * 'nullable' included, is skipped for that shape.
     */
    public function status(): PlanStatus
    {
        $status = $this->input('status');

        return PlanStatus::from(is_string($status) && trim($status) !== '' ? $status : PlanStatus::Active->value);
    }
}
