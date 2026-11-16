<?php

namespace App\Http\Requests\Api\V1\Admin;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Базовый FormRequest админ-API с legacy-форматом ошибок {"errors": {...}}.
 *
 * Пакет 5 (рекомендация №6 аудита Шага 1). ВАЖНО: это НЕ замена дефолтному
 * поведению существующих 48 админ-FormRequest'ов (те сконвертированы из
 * $request->validate() и рендерятся дефолтным ValidationException —
 * {"message": "...", "errors": {...}}).
 *
 * Этот класс — только для SettingController и PageController: они до Пакета 5
 * валидировались через Validator::make с РУЧНЫМ ответом {"errors": {...}}
 * в 422. Формат воспроизведён байт-в-байт, фронт админки не затронут.
 */
abstract class AdminJsonRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // RBAC — на middleware admin.role
    }

    protected function failedValidation(Validator $validator): void
    {
        throw new HttpResponseException(
            response()->json(['errors' => $validator->errors()], 422)
        );
    }
}
