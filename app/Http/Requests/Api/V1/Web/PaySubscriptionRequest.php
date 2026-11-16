<?php

namespace App\Http\Requests\Api\V1\Web;

/**
 * Валидация оплаты подписки (POST /api/subscriptions/pay и /renew).
 *
 * Пакет 5 (рекомендация №6 — дедупликация pay/renew): правила у обоих
 * методов были идентичными копиями — теперь один FormRequest.
 * Правила дословно из SubscriptionController::pay()/renew().
 * Формат 422 — {"errors": {...}} (как в обоих методах).
 */
class PaySubscriptionRequest extends WebJsonRequest
{
    public function rules(): array
    {
        return [
            'plan_id' => 'required|exists:subscription_plans,id',
            'payment_method' => 'required|in:card,yoomoney',
            'auto_renew' => 'boolean',
            'return_url' => 'nullable|string',
        ];
    }
}
