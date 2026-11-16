<?php 

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Cache;

class SubscriptionPlan extends Model
{
    use HasFactory;

    public const TIER_PREMIUM = 'premium';
    public const TIER_VIP = 'vip';

    protected $fillable = [
        'tier', 'name', 'slug', 'price', 'old_price', 'currency', 
        'duration_days', 'apple_product_id', 'google_product_id', 'is_active', 'sort_order',
    ];

    protected $casts = [
        'price' => 'decimal:2',
        'old_price' => 'decimal:2',
        'duration_days' => 'integer',
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    /**
     * Пакет 5 (рекомендация №8): сброс кэша тарифов
     * (SubscriptionController::plans, 10 минут). Ключей три: фильтр по tier
     * (premium/vip) + вариант «без фильтра».
     */
    protected static function booted(): void
    {
        $flush = function () {
            Cache::forget('subscription_plans:web:all');
            Cache::forget('subscription_plans:web:' . self::TIER_PREMIUM);
            Cache::forget('subscription_plans:web:' . self::TIER_VIP);
        };

        static::saved($flush);
        static::deleted($flush);
    }

    public function subscriptions(): HasMany {
        return $this->hasMany(UserSubscription::class);
    }

    public function scopeActive(Builder $query): Builder { 
        return $query->where('is_active', true); 
    }

    public function scopeOrdered(Builder $query): Builder { 
        return $query->orderBy('sort_order'); 
    }
}