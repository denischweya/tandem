<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Plans;

use App\Application\Plans\PageQuery;
use App\Http\Requests\Concerns\ReadsPageQuery;
use App\Http\Rules\ValidCursor;
use Illuminate\Foundation\Http\FormRequest;

final class ListPlanMembersRequest extends FormRequest
{
    use ReadsPageQuery;

    /**
     * No filter: spec §6.3 - "none; status = active only (§8.1)" is enforced in the
     * repository, not here, because it is not something a caller chooses.
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
        ];
    }
}
