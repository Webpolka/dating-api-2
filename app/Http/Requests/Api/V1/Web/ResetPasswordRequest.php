<?php

namespace App\Http\Requests\Api\V1\Web;

use Illuminate\Validation\Rules;

/**
 * Валидация установки нового пароля (POST /api/reset-password).
 *
 * Пакет 5 (рекомендация №6): правила дословно из AuthController::resetPassword().
 * Формат 422 — {"errors": {...}}.
 */
class ResetPasswordRequest extends WebJsonRequest
{
    public function rules(): array
    {
        return [
            'token' => 'required',
            'email' => 'required|email',
            'password' => ['required', 'string', 'confirmed', Rules\Password::defaults()],
        ];
    }
}
