<?php

namespace App\Http\Requests\Api\V1\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Валидация промокода ДО оплаты (POST /api/subscriptions/validate-promo).
 *
 * Юзер вводит код в форме оплаты → фронт дёргает этот endpoint →
 * бек проверяет isValidForUser и возвращает детали скидки.
 *
 * plan_id нужен для расчёта calculated_discount (для percent/fixed).
 */
class ValidatePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code'    => ['required', 'string', 'max:50'],
            'plan_id' => ['required', 'integer', 'exists:subscription_plans,id'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required'    => 'Введите промокод.',
            'plan_id.required' => 'Выберите тариф.',
            'plan_id.exists'   => 'Тариф не найден.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
} 