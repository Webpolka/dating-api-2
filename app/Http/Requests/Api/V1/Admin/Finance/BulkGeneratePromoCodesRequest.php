<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use App\Enums\V1\PromoCodeType;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Bulk-генерация промокодов (POST /admin/promo-codes/bulk-generate).
 *
 * Создаёт N кодов с одинаковыми параметрами. По образцу StorePromoCodeRequest,
 * но:
 *  - нет поля code (генерируется автоматически).
 *  - есть count (1-1000).
 *  - есть code_prefix (опционально — например "BLACKFRIDAY", даст коды BLACKFRIDAY-XXXX).
 */
class BulkGeneratePromoCodesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'count'          => ['required', 'integer', 'min:1', 'max:1000'],
            'discount_type'  => ['required', 'string', Rule::in(PromoCodeType::values())],
            'discount_value' => ['nullable', 'numeric', 'min:0', 'max:1000000'],
            'duration_days'  => ['nullable', 'integer', 'min:1', 'max:3650'],
            'max_uses'       => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'expires_at'     => ['nullable', 'date', 'after:now'],
            'user_id'        => ['nullable', 'integer', 'exists:users,id'],
            'is_active'      => ['nullable', 'boolean'],
            'code_prefix'    => ['nullable', 'string', 'max:20', 'regex:/^[A-Z0-9]+$/'],
        ];
    }

    public function messages(): array
    {
        return [
            'count.min'         => 'Минимум 1 код.',
            'count.max'         => 'Максимум 1000 кодов за один запрос.',
            'discount_type.in'  => 'Недопустимый тип скидки.',
            'code_prefix.regex' => 'Префикс — только заглавные латинские буквы и цифры.',
        ];
    }

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

    /** Подготовить данные: upper-case prefix. */
    protected function prepareForValidation(): void
    {
        if ($this->has('code_prefix') && is_string($this->input('code_prefix'))) {
            $this->merge(['code_prefix' => strtoupper(trim($this->input('code_prefix')))]);
        }
    }
}
