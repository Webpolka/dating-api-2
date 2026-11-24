<?php

namespace Database\Seeders;

use App\Enums\V1\PromoCodeType;
use App\Models\PromoCode;
use App\Models\PromoCodeUsage;
use App\Models\Transaction;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class PromoCodeUsagesSeeder extends Seeder
{
    public function run(): void
    {
        $this->command->info('🎟️ Генерируем историю использования промокодов (массовая вставка)...');

        PromoCodeUsage::truncate();
        PromoCode::query()->update(['used_count' => 0]);

        $users = User::where('role', 'user')->get();

        if ($users->isEmpty()) {
            $this->command->warn('⚠️ Нет юзеров для генерации.');
            return;
        }

        // Грузим все промокоды и транзакции в память (O(1) запросов).
        // ВАЖНО: только TYPE_SUBSCRIPTION — промокоды (percent/fixed) применяются
        // к оплате подписки, не к покупке кредитов. Credits-транзакции исключаем,
        // иначе calculateDiscount() считала бы скидку с цены кредит-пака.
        $promoCodes = PromoCode::all();
        $transactions = Transaction::where('status', 'success')
            ->where('type', Transaction::TYPE_SUBSCRIPTION)
            ->get()->groupBy('user_id');

        $usagesData = [];
        $transactionsToUpdate = []; // [transaction_id => [plan_price, final_amount, discount, promo_code_id]]
        $promoUsageCounts = [];
        $now = now();

        $bar = $this->command->getOutput()->createProgressBar($users->count());

        foreach ($users as $user) {
            // 40% юзеров использовали промокод
            if (rand(1, 10) > 4) {
                $bar->advance();
                continue;
            }

            // Фильтруем валидные промокоды в памяти
            $validPromos = $promoCodes->filter(fn($p) => $p->isValidForUser($user))->values();

            if ($validPromos->isEmpty()) {
                $bar->advance();
                continue;
            }

            // Берём один случайный валидный промокод
            $promo = $validPromos->random();

            // Для percent/fixed — нужна транзакция (оплата со скидкой).
            // Для premium/vip — redeem без транзакции (бесплатные дни).
            $transaction = null;
            $givesFreeDays = in_array($promo->discount_type, [PromoCodeType::Premium->value, PromoCodeType::Vip->value]);

            if (!$givesFreeDays) {
                $userTransactions = $transactions->get($user->id);
                if (empty($userTransactions)) {
                    $bar->advance();
                    continue;
                }
                $transaction = $userTransactions->random();
            }

            $date = $now->copy()->subDays(rand(1, 30));

            $usagesData[] = [
                'promo_code_id' => $promo->id,
                'user_id' => $user->id,
                'transaction_id' => $transaction?->id,
                'applied_discount' => 0, // заполним ниже для percent/fixed
                'created_at' => $date,
                'updated_at' => $date,
            ];

            // Для percent/fixed — считаем финансы консистентно.
            // plan_price = полная цена плана (transaction.amount на момент сида,
            //   т.к. FinanceHistorySeeder создаёт транзакции с amount = plan->price).
            // discount = calculateDiscount(plan_price).
            // final_amount = plan_price - discount (то, что юзер реально заплатил).
            //
            // КОНСИСТЕНТНОСТЬ (проверяется на фронте и в отчётах):
            //   transaction.amount (final) + applied_discount (discount)
            //   == transaction.meta.original_amount (plan_price)
            //
            // РАНЬШЕ БЫЛ КОСТЫЛЬ: original_amount = amount + discount — это давало
            // значение БОЛЬШЕ цены плана (amount уже был полной ценой, прибавление
            // discount раздувало original). Теперь original_amount = plan_price
            // сохраняется как снапшот, а amount обновляется до final_amount.
            if ($transaction) {
                $planPrice = (float) $transaction->amount;
                $discount = (float) $promo->calculateDiscount($planPrice);
                $finalAmount = max(0, $planPrice - $discount);

                // Заполняем applied_discount в данных usage (для bulk insert).
                $usagesData[count($usagesData) - 1]['applied_discount'] = $discount;

                // Запоминаем, какую транзакцию надо обновить после bulk insert.
                $transactionsToUpdate[$transaction->id] = [
                    'plan_price'    => $planPrice,    // original_amount
                    'final_amount'  => $finalAmount,  // новый transaction.amount
                    'discount'      => $discount,     // promo_code_discount
                    'promo_code_id' => $promo->id,
                ];
            }

            // Увеличиваем счетчик в памяти
            if (!isset($promoUsageCounts[$promo->id])) {
                $promoUsageCounts[$promo->id] = 0;
            }
            $promoUsageCounts[$promo->id]++;

            $bar->advance();
        }

        // Массовая вставка историй использования (1 запрос)
        if (!empty($usagesData)) {
            PromoCodeUsage::insert($usagesData);
        }

        // Обновляем счетчики used_count у промокодов
        foreach ($promoUsageCounts as $promoId => $count) {
            PromoCode::where('id', $promoId)->increment('used_count', $count);
        }

        $bar->finish();
        $this->command->newLine(2);

        // ОБНОВЛЯЕМ ТРАНЗАКЦИИ: для каждого percent/fixed-usage:
        //   1. amount → final_amount (то, что юзер реально заплатил после скидки)
        //   2. meta.original_amount → plan_price (цена плана ДО скидки)
        //   3. meta.promo_code_id → ID промокода
        //   4. meta.promo_code_discount → размер скидки
        //
        // Это делает данные КОНСИСТЕНТНЫМИ:
        //   amount (final) + applied_discount (discount) == original_amount (plan_price)
        //
        // TransactionResource и PromoCodeUsageResource отдают original_amount из meta,
        // фронт использует его для колонки "Исходная" — НЕ вычисляя amount + discount.
        $updatedTransactions = 0;
        foreach ($transactionsToUpdate as $txId => $fin) {
            DB::table('transactions')
                ->where('id', $txId)
                ->update([
                    // amount — итоговая сумма после скидки (была plan_price, стала final).
                    'amount' => $fin['final_amount'],
                    // meta — сливаем с существующим, добавляем promo-поля.
                    'meta' => DB::raw("COALESCE(meta, '{}'::jsonb) || jsonb_build_object(
                        'promo_code_id', {$fin['promo_code_id']}::int,
                        'promo_code_discount', {$fin['discount']}::numeric,
                        'original_amount', {$fin['plan_price']}::numeric
                    )"),
                ]);
            $updatedTransactions++;
        }

        $this->command->info("✅ Реалистично применено промокодов: " . count($usagesData));
        $this->command->info("   📝 Обновлено транзакций (amount + meta): {$updatedTransactions}");
    }
} 