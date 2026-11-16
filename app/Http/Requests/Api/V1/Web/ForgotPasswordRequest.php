<?php

namespace App\Http\Requests\Api\V1\Web;

/**
 * Валидация запроса сброса пароля (POST /api/forgot-password).
 *
 * Пакет 5 (рекомендация №6): правила дословно из AuthController::forgotPassword().
 * Формат 422 — {"errors": {...}}.
 */
class ForgotPasswordRequest extends WebJsonRequest
{
    public function rules(): array
    {
        return [
            'email' => 'required|email',
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
