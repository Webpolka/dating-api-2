<?php

namespace App\Http\Requests\Api\V1\Web;

/**
 * Валидация входа (POST /api/login).
 *
 * Пакет 5 (рекомендация №6): правила дословно из AuthController::login().
 * Формат 422 — {"errors": {...}} (legacy-контракт Web-API).
 */
class LoginRequest extends WebJsonRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
            'password' => 'required|string',
            'captchaInput' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return [
            'captchaInput.required' => 'Введите код с картинки.',
        ];
    }
}
