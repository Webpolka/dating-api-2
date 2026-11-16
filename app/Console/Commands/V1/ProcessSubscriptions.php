<?php

namespace App\Console\Commands\V1;

use App\Models\UserSubscription;
use Illuminate\Console\Command;

class ProcessSubscriptions extends Command
{
    protected $signature = 'subscriptions:process';
    protected $description = 'Снимать просроченные VIP-статусы и слать пуши об истечении подписки';

    public function handle(): int
    {
        $this->info('Начинаем обработку подписок...');

        // === 1. СНИМАЕМ ПРОСРОЧЕННЫЕ ПОДПИСКИ ===
        $expiredCount = 0;
        UserSubscription::query()
            ->overdue()
            ->chunkById(100, function ($subscriptions) use (&$expiredCount) {
                foreach ($subscriptions as $subscription) {
                    $subscription->expire(); // Observer сам снимет is_premium и кинет SendVipExpiredNotification
                    $expiredCount++;
                }
            });
        $this->info("Снято просроченных подписок: {$expiredCount}");


        // === 2. ПУШИ: ЗАКАНЧИВАЮТСЯ ЧЕРЕЗ 24 ЧАСА (БЕЗ СПАМА) ===
        $notifyCount = 0;
        UserSubscription::query()
            ->expiringSoon(24)
            ->whereNull('expires_notified_at') // <--- КЛЮЧЕВАЯ ЗАЩИТА ОТ СПАМА! Если уже уведомляли - пропускаем
            ->chunkById(100, function ($subscriptions) use (&$notifyCount) {
                foreach ($subscriptions as $subscription) {
                    // АТОМАРНАЯ ЗАХВАТ-МЕТКА (FIX, В6): помечаем expires_notified_at
                    // только если она ещё не поставлена (WHERE ... IS NULL + проверка
                    // затронутых строк). Раньше метка ставилась безусловно сразу после
                    // dispatch: два параллельных запуска шедулера (нет onOneServer)
                    // успевали оба сделать dispatch → дубли пушей. Теперь метку
                    // получает ровно один запуск, остальные просто видят 0 строк.
                    $claimed = UserSubscription::where('id', $subscription->id)
                        ->whereNull('expires_notified_at')
                        ->update(['expires_notified_at' => now()]);

                    if ($claimed === 0) {
                        continue; // Метку уже забрал параллельный процесс
                    }

                    // В очередь — только ID (job сам перечитает свежие данные,
                    // см. SendSubscribeExpiringNotification)
                    \App\Jobs\V1\Admin\Finance\SendSubscribeExpiringNotification::dispatch($subscription->id);
                    $notifyCount++;
                }
            });
        $this->info("Отправлено уведомлений об истечении: {$notifyCount}");

        return Command::SUCCESS;
    }
}