<?php

namespace App\Http\Requests\Api\V1\Web;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Активация промокода на бесплатные дни (POST /api/promo-codes/redeem).
 *
 * Только для premium/vip кодов (discount_type in [premium, vip] с duration_days).
 * Для percent/fixed — 422 "применяется при оплате".
 */
class RedeemPromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'code' => ['required', 'string', 'max:50'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.required' => 'Введите промокод.',
        ];
    }

    protected function prepareForValidation(): void
    {
        if ($this->has('code') && is_string($this->input('code'))) {
            $this->merge(['code' => strtoupper(trim($this->input('code')))]);
        }
    }
}
