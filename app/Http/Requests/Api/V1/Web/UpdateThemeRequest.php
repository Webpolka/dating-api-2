<?php

namespace App\Http\Requests\Api\V1\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация сохранения темы (POST /api/settings/theme).
 *
 * Пакет 5 (рекомендация №6, хвост): роут до этого был инлайн-замыканием
 * в routes/api.php с $request->validate() — FormRequest к замыканию не
 * привязать. Логика вынесена в SettingsController::updateTheme().
 *
 * Формат 422 — ДЕФОЛТНЫЙ ValidationException ({"message": "...",
 * "errors": {...}}), ровно как раньше у $request->validate() в замыкании:
 * поэтому наследует FormRequest напрямую, БЕЗ legacy-оверрайда WebJsonRequest.
 */
class UpdateThemeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // роут под auth:sanctum
    }

    public function rules(): array
    {
        return [
            'theme' => 'required|in:light,dark',
        ];
    }
}
