<?php

namespace App\Http\Requests\Api\V1\Web;

use App\Models\User;
use Illuminate\Validation\Rule;

/**
 * Валидация смены email (POST /api/email/change).
 *
 * Пакет 5 (рекомендация №6): правила дословно из AuthController::changeEmail().
 * ignore($this->user()->id) — как в оригинале (свой email разрешён).
 * Формат 422 — {"errors": {...}}.
 */
class ChangeEmailRequest extends WebJsonRequest
{
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'lowercase', 'email', 'max:255',
                Rule::unique(User::class, 'email')->ignore($this->user()?->id)],
        ];
    }
}
