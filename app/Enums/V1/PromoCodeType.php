<?php

namespace App\Enums\V1;

/**
 * Тип промокода.
 *
 * Симметрично с PhotoRejectReason / VerificationRejectReason по структуре.
 * Values sync с миграцией 0001_01_01_000041_create_promo_codes_table.php
 * (enum-колонка discount_type в БД).
 *
 * Семантика:
 *  - percent / fixed — скидка на оплату подписки (применяется в форме оплаты,
 *    amount транзакции уменьшается).
 *  - premium / vip — бесплатные дни подписки (без оплаты, redeem-флоу
 *    через POST /api/promo-codes/redeem). duration_days указывает сколько дней.
 */
enum PromoCodeType: string
{
    case Percent = 'percent';
    case Fixed = 'fixed';
    case Premium = 'premium';
    case Vip = 'vip';

    public function label(): string
    {
        return match ($this) {
            self::Percent => 'Скидка %',
            self::Fixed   => 'Скидка ₽',
            self::Premium => 'Бесплатные дни Premium',
            self::Vip     => 'Бесплатные дни VIP',
        };
    }

    /** Цвет для badge'а на фронте (sync с lib/types / status-badges). */
    public function color(): string
    {
        return match ($this) {
            self::Percent => 'bg-blue-500/10 text-blue-500',
            self::Fixed   => 'bg-cyan-500/10 text-cyan-500',
            self::Premium => 'bg-orange-500/10 text-orange-500',
            self::Vip     => 'bg-purple-500/10 text-purple-500',
        };
    }

    /**
     * Даёт ли этот тип бесплатные дни подписки (premium/vip).
     * Используется в Web\PromoCodeController::redeem и validate
     * чтобы отличить flow "применить скидку" от "активировать дни".
     */
    public function givesFreeDays(): bool
    {
        return in_array($this, [self::Premium, self::Vip], true);
    }

    /**
     * Требует ли этот тип discount_value (для валидации в FormRequest).
     * percent/fixed — да, premium/vip — нет (они используют duration_days).
     */
    public function requiresDiscountValue(): bool
    {
        return in_array($this, [self::Percent, self::Fixed], true);
    }

    /**
     * Требует ли этот тип duration_days.
     * premium/vip — да, percent/fixed — нет.
     */
    public function requiresDurationDays(): bool
    {
        return $this->givesFreeDays();
    }

    /** Опции для Select: value => label. */
    public static function options(): array
    {
        return collect(self::cases())->mapWithKeys(fn(self $case) => [
            $case->value => $case->label(),
        ])->toArray();
    }

    /** Массив всех значений (для Rule::in в FormRequest). */
    public static function values(): array
    {
        return collect(self::cases())->map(fn(self $case) => $case->value)->all();
    }

    /** Label по строковому значению — для badge на уже сохранённых кодах. */
    public static function labelFor(?string $type): string
    {
        if (!$type) return '—';
        try {
            return self::from($type)->label();
        } catch (\ValueError $e) {
            return $type;
        }
    }
}
