<?php

namespace App\Http\Requests\Api\V1\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/user/interests/custom — предложить свой интерес по имени.
 */
class ProposeCustomInterestRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'min:2', 'max:50', 'regex:/^[\p{L}\p{N}\s\-]+$/u'],
        ];
    }

    public function messages(): array
    {
        return [
            'name.required' => 'Введите название интереса.',
            'name.min'      => 'Название интереса — минимум :min символа.',
            'name.max'      => 'Название интереса — максимум :max символов.',
            'name.regex'    => 'Название может содержать только буквы, цифры, пробел и дефис.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('name') && is_string($this->input('name'))) {
            $this->merge(['name' => trim($this->input('name'))]);
        }
    }
}
