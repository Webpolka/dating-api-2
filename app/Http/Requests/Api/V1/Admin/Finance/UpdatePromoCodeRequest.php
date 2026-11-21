<?php

namespace App\Http\Requests\Api\V1\Admin\Finance;

use App\Models\PromoCode;
use Carbon\Carbon;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Обновление промокода (PUT /admin/promo-codes/{id}).
 *
 * Строгая политика (best practice Stripe/Shopify): после создания
 * промокод **полностью immutable** по semantic-полям. Разрешены
 * к изменению ТОЛЬКО 3 поля:
 *
 *  ✅ max_uses — только УВЕЛИЧИВАТЬ (если код уже использовали).
 *  ✅ expires_at — только ПРОДЛЕВАТЬ (если код уже использовали).
 *  ✅ is_active — toggle (вкл/выкл).
 *
 * Запрещены всегда:
 *  ❌ code — immutable (идентификатор).
 *  ❌ discount_type — immutable (тип определяет semantic).
 *  ❌ discount_value — immutable (размер скидки зафиксирован при создании;
 *     snapshot в promo_code_usages.applied_discount хранит фактическую
 *     скидку на момент применения — история корректна, но менять значение
 *     в карточке кода путает админ-интерфейс).
 *  ❌ duration_days — immutable (аналогично).
 *  ❌ user_id — immutable (персональный код нельзя "перепривязать").
 *
 * Если админ ошибся при создании — пусть создаст новый промокод
 * и деактивирует старый. Это безопаснее чем "докрутить" semantic
 * используемого кода.
 */
class UpdatePromoCodeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'max_uses'   => ['nullable', 'integer', 'min:1', 'max:10000000'],
            'expires_at' => ['nullable', 'date'],
            'is_active'  => ['nullable', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            'max_uses.min' => 'Лимит — минимум 1.',
        ];
    }

    /**
     * Семантическая валидация после базовой:
     *  - Если used_count > 0 → max_uses только ↑, expires_at только продление.
     *  - Если used_count === 0 → свободное изменение в рамках rules() (можно
     *    и уменьшить max_uses, и сократить expires_at).
     */
    public function withValidator($validator): void
    {
        $validator->after(function ($v) {
            $id = (int) $this->route('id');
            $promo = PromoCode::find($id);
            if (!$promo) {
                return; // 404 обработает контроллер.
            }

            // Код ещё не использовали — свободное редактирование 3 полей.
            if ($promo->used_count === 0) {
                return;
            }

            $usedCount = $promo->used_count;

            // max_uses — только увеличение (не ниже used_count).
            if ($this->has('max_uses') && $this->filled('max_uses')) {
                $newMax = (int) $this->input('max_uses');
                if ($newMax < $usedCount) {
                    $v->errors()->add('max_uses', "Лимит нельзя уменьшить ниже уже использованных ({$usedCount}). Только увеличивать.");
                }
            }

            // expires_at — только продление (не раньше текущего expires_at).
            if ($this->has('expires_at') && $this->filled('expires_at')) {
                $newExpiry = Carbon::parse($this->input('expires_at'));
                if ($promo->expires_at && $newExpiry->lt($promo->expires_at)) {
                    $v->errors()->add('expires_at', 'Дату истечения можно только продлить, не сократить (код уже использовали).');
                }
            }
        });
    }
}
