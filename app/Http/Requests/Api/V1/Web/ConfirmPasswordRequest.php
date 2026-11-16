<?php

namespace App\Http\Requests\Api\V1\Web;

/**
 * Валидация подтверждения пароля (POST /api/confirm-password).
 *
 * Пакет 5 (рекомендация №6): правила дословно из AuthController::confirmPassword().
 * Формат 422 — {"errors": {...}}.
 */
class ConfirmPasswordRequest extends WebJsonRequest
{
    public function rules(): array
    {
        return [
            'password' => 'required|string',
        ];
    }
}
