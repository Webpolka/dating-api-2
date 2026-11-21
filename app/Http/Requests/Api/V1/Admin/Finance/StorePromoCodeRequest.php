<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use App\Enums\V1\PromoCodeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Создание промокода (POST /admin/promo-codes).
 *
 * code — nullable: если не передан, бек сгенерирует случайный
 * (PromoCode::generateCode()). Если передан — upper-case + проверка unique.
 *
 * discount_type — enum PromoCodeType.
 * discount_value — required для percent/fixed (каков скидка), ignored для premium/vip.
 * duration_days — required для premium/vip (сколько дней), ignored для percent/fixed.
 * max_uses — nullable (null = безлимитный).
 * expires_at — nullable (null = бессрочный).
 * user_id — nullable (null = общий код, иначе персональный).
 * is_active — bool, default true.
 */
class StorePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        // Доступ ограничен middleware admin.role:admin.
        return true;
    }

    public function rules(): array
    {
        return [
            'code'           => ['nullable', 'string', 'max:50', 'regex:/^[A-Z0-9\-]+$/i'],
            'discount_type'  => ['required', 'string', Rule::in(PromoCodeType::values())],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'duration_days'  => ['nullable', 'integer', 'min:1', 'max:3650'],
            'max_uses'       => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'expires_at'     => ['nullable', 'date', 'after:now'],
            'user_id'        => ['nullable', 'integer', 'exists:users,id'],
            'is_active'      => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'code.regex'         => 'Код может содержать только латинские буквы, цифры и дефис.',
            'code.max'           => 'Код не длиннее 50 символов.',
            'discount_type.in'   => 'Недопустимый тип скидки.',
            'discount_value.min' => 'Скидка не может быть отрицательной.',
            'duration_days.min'  => 'Длительность — минимум 1 день.',
            'expires_at.after'   => 'Дата истечения должна быть в будущем.',
            'user_id.exists'     => 'Юзер не найден.',
        ];
    }

    /**
     * Дополнительная семантическая валидация после базовой:
     *  - percent/fixed → требуется discount_value > 0.
     *  - premium/vip → требуется duration_days.
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $type = PromoCodeType::tryFrom((string) $this->input('discount_type', ''));
            if (!$type) return;

            if ($type->requiresDiscountValue()) {
                if (!((float) $this->input('discount_value', 0)) > 0) {
                    $v->errors()->add('discount_value', "Для типа «{$type->label()}» укажите размер скидки (> 0).");
                }
            }

            if ($type->requiresDurationDays()) {
                if (!$this->filled('duration_days') || (int) $this->input('duration_days') <= 0) {
                    $v->errors()->add('duration_days', "Для типа «{$type->label()}» укажите количество дней.");
                }
            }
        });
    }
}
