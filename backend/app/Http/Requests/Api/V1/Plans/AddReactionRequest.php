<?php

declare(strict_types=1);

namespace App\Http\Requests\Api\V1\Plans;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

final class AddReactionRequest extends FormRequest
{
    /** @return array<string, mixed> */
    public function rules(): array
    {
        return [
            'reaction' => ['required', Rule::in(['👍', '❤️', '😂', '🎉', '❓'])],
        ];
    }
}
