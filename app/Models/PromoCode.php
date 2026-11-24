<?php

namespace App\Models;

use App\Enums\V1\PromoCodeType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class PromoCode extends Model
{
    use HasFactory;

    // NOTE: Типы промокодов вынесены в enum App\Enums\V1\PromoCodeType.
    // Констант TYPE_* здесь больше нет — используй PromoCodeType::Percent->value,
    // PromoCodeType::values() для валидации, PromoCodeType::labelFor() для UI.

    /**
     * Алфавит для generateCode(): без похожих символов (0/O, 1/I/L),
     * чтобы юзер не путал при ручном вводе.
     */
    private const CODE_ALPHABET = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    /**
     * Длина генерируемого кода по умолчанию.
     * 10 символов из алфавита 32 → 32^10 ≈ 10^15 комбинаций — достаточно
     * для bulk-генерации (1000 кодов) без коллизий.
     */
    public const GENERATED_CODE_LENGTH = 10;

    protected $fillable = [
        'code',
        'discount_type',
        'discount_value',
        'duration_days',
        'max_uses',
        'used_count',
        'expires_at',
        'user_id',
        'is_active',
    ];

    protected $casts = [
        'discount_value' => 'decimal:2',
        'duration_days'  => 'integer',
        'max_uses'       => 'integer',
        'used_count'     => 'integer',
        'expires_at'     => 'datetime',
        'is_active'      => 'boolean',
    ];

    // ============================================
    // СВЯЗИ
    // ============================================
    public function user(): BelongsTo
    {
        // Персональный промокод — юзер, которому он выдан (nullable для общих).
        return $this->belongsTo(User::class);
    }

    public function usages(): HasMany
    {
        return $this->hasMany(PromoCodeUsage::class);
    }

    // ============================================
    // СКОПЫ
    // ============================================
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }

    public function scopeInactive(Builder $query): Builder
    {
        return $query->where('is_active', false);
    }

    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNotNull('expires_at')->where('expires_at', '<', now());
    }

    public function scopeNotExpired(Builder $query): Builder
    {
        return $query->where(function ($q) {
            $q->whereNull('expires_at')->orWhere('expires_at', '>=', now());
        });
    }

    public function scopeGeneral(Builder $query): Builder
    {
        return $query->whereNull('user_id');
    }

    public function scopePersonal(Builder $query): Builder
    {
        return $query->whereNotNull('user_id');
    }

    public function scopeByType(Builder $query, PromoCodeType|string $type): Builder
    {
        $value = $type instanceof PromoCodeType ? $type->value : $type;
        return $query->where('discount_type', $value);
    }

    // ============================================
    // БИЗНЕС-ЛОГИКА
    // ============================================

    public function isExpired(): bool
    {
        return $this->expires_at !== null && $this->expires_at->isPast();
    }

    public function isPersonal(): bool
    {
        return $this->user_id !== null;
    }

    public function isFullyUsed(): bool
    {
        return $this->max_uses !== null && $this->used_count >= $this->max_uses;
    }

    /**
     * Остаток использований (max_uses - used_count).
     * null — если лимит не задан (безлимитный код).
     */
    public function remainingUses(): ?int
    {
        if ($this->max_uses === null) {
            return null;
        }
        return max(0, $this->max_uses - $this->used_count);
    }

    public function isValidForUser(User $user): bool
    {
        if (!$this->is_active) return false;
        if ($this->isExpired()) return false;
        if ($this->isFullyUsed()) return false;
        if ($this->isPersonal() && $this->user_id !== $user->id) return false;

        return !$this->usages()->where('user_id', $user->id)->exists();
    }

    public function calculateDiscount(float $originalAmount): float
    {
        $type = PromoCodeType::tryFrom($this->discount_type)
            ?? PromoCodeType::Percent;

        if ($type === PromoCodeType::Percent) {
            return round($originalAmount * ((float) $this->discount_value / 100), 2);
        }

        if ($type === PromoCodeType::Fixed) {
            return min($originalAmount, (float) $this->discount_value);
        }

        // premium/vip — скидка на сумму не считается, они дают дни.
        return 0;
    }

    /**
     * Применить промокод к транзакции (или без — для premium/vip redeem).
     *
     * Идемпотентно внутри: increment used_count с WHERE used_count < max_uses
     * (race condition защита — два юзера одновременно не переполнят лимит).
     * UNIQUE(promo_code_id, user_id) в promo_code_usages — двойная защита
     * от повторного использования одним юзером.
     *
     * @return PromoCodeUsage|null null если код невалиден или race-condition
     *   по лимиту — вызывающий код должен вернуть 422.
     */
    public function apply(User $user, ?Transaction $transaction = null): ?PromoCodeUsage
    {
        if (!$this->isValidForUser($user)) {
            return null;
        }

        try {
            return DB::transaction(function () use ($user, $transaction) {
                // WHERE used_count < max_uses — atomic increment, защита от race.
                $updated = $this->newQuery()
                    ->where('id', $this->id)
                    ->when($this->max_uses, fn($q) => $q->where('used_count', '<', $this->max_uses))
                    ->increment('used_count');

                if (!$updated) {
                    throw new \Exception("Промокод исчерпал лимит использований.");
                }

                $this->used_count++; // синхронизируем in-memory

                $appliedDiscount = 0;
                if ($transaction) {                  
                    // ВАЖНО: берём скидку из transaction.meta['promo_code_discount']
                    // (правильно вычислена в SubscriptionController от plan.price),
                    // а НЕ вычисляем заново от transaction.amount (который уже
                    // ПОСЛЕ скидки — calculateDiscount(finalAmount) даст неверный
                    // результат для percent: finalAmount * % ≠ originalAmount * %).
                    $metaDiscount = $transaction->meta['promo_code_discount'] ?? null;
                    $appliedDiscount = $metaDiscount !== null
                        ? (float) $metaDiscount
                        : $this->calculateDiscount((float) $transaction->amount);
                }

                return PromoCodeUsage::create([
                    'promo_code_id'   => $this->id,
                    'user_id'         => $user->id,
                    'transaction_id'  => $transaction?->id,
                    'applied_discount' => $appliedDiscount,
                ]);
            });
        } catch (\Exception $e) {
            return null;
        }
    }

    // ============================================
    // СТАТИЧЕСКИЕ ХЕЛПЕРЫ
    // ============================================

    /**
     * Сгенерировать случайный код заданной длины (без похожих символов).
     * Используется в админке (ManagePromoCodeAction::store при пустом code,
     * и в bulkGenerate).
     *
     * С опциональным prefix'ом: "PROMO-" + 8 рандомных символов — для
     * визуальной группировки bulk-кампаний.
     */
    public static function generateCode(int $length = self::GENERATED_CODE_LENGTH, ?string $prefix = null): string
    {
        $body = collect(range(1, $length))
            ->map(fn () => self::CODE_ALPHABET[random_int(0, strlen(self::CODE_ALPHABET) - 1)])
            ->implode('');

        $code = $prefix !== null && $prefix !== ''
            ? Str::upper($prefix) . '-' . $body
            : $body;

        return Str::upper($code);
    }

    /**
     * Найти промокод по коду (case-insensitive в PG через ilike не нужен —
     * коды хранятся uppercase, lookup делаем тоже uppercase).
     */
    public static function findByCode(string $code): ?self
    {
        return self::where('code', Str::upper(trim($code)))->first();
    }
}
