<?php

namespace App\Http\Requests\Api\V1\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/user/interests — добавить себе интерес из справочника.
 */
class AttachInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'interest_id' => ['required', 'integer', 'min:1', 'exists:interests,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'interest_id.required' => 'Не указан интерес.',
            'interest_id.integer'  => 'ID интереса должен быть числом.',
            'interest_id.min'      => 'Некорректный ID интереса.',
            'interest_id.exists'   => 'Такой интерес не существует.',
        ];
    }
}
