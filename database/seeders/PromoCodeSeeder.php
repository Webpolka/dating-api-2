<?php

namespace Database\Seeders;

use App\Enums\V1\PromoCodeType;
use App\Models\PromoCode;
use App\Models\User;
use Illuminate\Database\Seeder;

class PromoCodeSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🎁 Создаем промокоды (массовая вставка)...');

        PromoCode::truncate();

        $now = now();

        // Используем enum PromoCodeType::*->value (констант TYPE_* в модели больше нет).
        $promosData = [
            [
                'code' => 'WELCOME20', 'discount_type' => PromoCodeType::Percent->value, 'discount_value' => 20,
                'duration_days' => null, 'max_uses' => null, 'used_count' => 0, 'expires_at' => null, 'user_id' => null,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'SAVE500', 'discount_type' => PromoCodeType::Fixed->value, 'discount_value' => 500.00,
                'duration_days' => null, 'max_uses' => 1000, 'used_count' => 150, 'expires_at' => null, 'user_id' => null,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'FREEPREM14', 'discount_type' => PromoCodeType::Premium->value, 'discount_value' => 0,
                'duration_days' => 14, 'max_uses' => 50, 'used_count' => 0, 'expires_at' => null, 'user_id' => null,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'FREEVIP7', 'discount_type' => PromoCodeType::Vip->value, 'discount_value' => 0,
                'duration_days' => 7, 'max_uses' => 50, 'used_count' => 0, 'expires_at' => null, 'user_id' => null,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'EXPIRED10', 'discount_type' => PromoCodeType::Percent->value, 'discount_value' => 10,
                'duration_days' => null, 'max_uses' => null, 'used_count' => 0, 'expires_at' => $now->copy()->subMonth(), 'user_id' => null,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'LIMIT99', 'discount_type' => PromoCodeType::Fixed->value, 'discount_value' => 99.00,
                'duration_days' => null, 'max_uses' => 100, 'used_count' => 100, 'expires_at' => null, 'user_id' => null,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'BLACKFRIDAY', 'discount_type' => PromoCodeType::Percent->value, 'discount_value' => 50,
                'duration_days' => null, 'max_uses' => 500, 'used_count' => 42, 'expires_at' => $now->copy()->addDays(30), 'user_id' => null,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'NEWYEARVIP', 'discount_type' => PromoCodeType::Vip->value, 'discount_value' => 0,
                'duration_days' => 30, 'max_uses' => 100, 'used_count' => 12, 'expires_at' => $now->copy()->addDays(60), 'user_id' => null,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ],
            [
                'code' => 'INACTIVE50', 'discount_type' => PromoCodeType::Percent->value, 'discount_value' => 50,
                'duration_days' => null, 'max_uses' => null, 'used_count' => 5, 'expires_at' => null, 'user_id' => null,
                'is_active' => false, 'created_at' => $now, 'updated_at' => $now,
            ],
        ];

        // Персональный промокод для первого юзера
        $firstUser = User::where('role', 'user')->first();
        if ($firstUser) {
            $promosData[] = [
                'code' => 'PETYA2024', 'discount_type' => PromoCodeType::Percent->value, 'discount_value' => 50,
                'duration_days' => null, 'max_uses' => 1, 'used_count' => 0, 'expires_at' => null, 'user_id' => $firstUser->id,
                'is_active' => true, 'created_at' => $now, 'updated_at' => $now,
            ];
        }

        // Массовая вставка (1 запрос)
        PromoCode::insert($promosData);

        $this->command->info('✅ Создано ' . count($promosData) . ' тестовых промокодов.');
    }
}
