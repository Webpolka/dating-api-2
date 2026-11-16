<?php

namespace App\Http\Requests\Api\V1\Web;

/**
 * Валидация шага 1 регистрации (POST /api/register/step1).
 *
 * Пакет 5 (рекомендация №6): правила дословно из AuthController::validateStep1().
 * Формат 422 — {"message": "The given data was invalid.", "errors": {...}}
 * (legacy validateJson()).
 */
class ValidateRegisterStep1Request extends WebJsonRequest
{
    protected bool $withMessage = true;

    public function rules(): array
    {
        return [
            'birth_day' => ['required', 'integer', 'between:1,31'],
            'birth_month' => ['required', 'integer', 'between:1,12'],
            'birth_year' => ['required', 'integer', 'between:1950,2010'],
            'name' => ['required', 'string', 'max:255'],
            'gender' => ['required', 'in:male,female'],
        ];
    }
}
