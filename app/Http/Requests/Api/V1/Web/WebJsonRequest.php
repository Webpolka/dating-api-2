<?php

namespace App\Http\Requests\Api\V1\Web;

use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;

/**
 * Базовый FormRequest пользовательского Web-API.
 *
 * Пакет 5 (рекомендация №6 аудита Шага 1): до него Web-контроллеры
 * валидировались через Validator::make прямо в методах с ручным формированием
 * 422-ответа. В проекте есть эталонный слой FormRequest (72 класса), но его
 * дефолтная ошибка (ValidationException) рендерится как
 * {"message": "...", "errors": {...}}, а legacy-контракт пользовательского
 * API — {"errors": {...}} без message.
 *
 * Этот базовый класс воспроизводит ОБА legacy-формата байт-в-байт:
 *   - по умолчанию: {"errors": {...}}
 *     (login, forgot-password, reset-password, change-email,
 *      confirm-password, subscriptions/pay, subscriptions/renew);
 *   - $withMessage = true:
 *     {"message": "The given data was invalid.", "errors": {...}}
 *     (register, register/step1, register/step2 — бывший хелпер validateJson()
 *      из AuthController).
 *
 * Новые Web-FormRequest'ы наследуют этот класс — форматы ответов,
 * на которые подписан фронтенд, не меняются.
 */
abstract class WebJsonRequest extends FormRequest
{
    /**
     * Добавлять ли поле message в ответ 422 (legacy-формат validateJson()).
     */
    protected bool $withMessage = false;

    public function authorize(): bool
    {
        return true; // public-роуты / роуты под auth:sanctum
    }

    protected function failedValidation(Validator $validator): void
    {
        $payload = $this->withMessage
            ? ['message' => 'The given data was invalid.', 'errors' => $validator->errors()]
            : ['errors' => $validator->errors()];

        throw new HttpResponseException(response()->json($payload, 422));
    }
}
