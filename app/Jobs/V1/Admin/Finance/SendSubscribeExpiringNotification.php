<?php

namespace App\Jobs\V1\Admin\Finance;

use App\Models\UserSubscription;
use App\Notifications\V1\SubscriptionExpiringSoonNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Пуш «подписка истекает через ~24 часа».
 *
 * FIX (пакет «Деньги», В6): в очередь передаётся ТОЛЬКО ID подписки, в
 * handle() модель перечитывается из БД (по образцу
 * SendSubscribeExpiredNotification). Раньше в очереди лежал «слепок»
 * модели: если подписку продлевали/переносили, пока job стоял в очереди,
 * isActive()/between() считали по УСТАРЕВШИМ данным и юзер получал
 * ложный пуш об истечении только что продлённой подписки.
 */
class SendSubscribeExpiringNotification implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $tries = 3;

    public function __construct(
        protected int $subscriptionId
    ) {}

    public function handle(): void
    {
        // Свежие данные из БД, а не «слепок» на момент диспетча
        $subscription = UserSubscription::with(['user', 'plan'])->find($this->subscriptionId);

        if (!$subscription) {
            return; // Подписка удалена, пока job лежала в очереди
        }

        // ЗАЩИТА ОТ ГОНКИ И УСТАРЕВШИХ ДАННЫХ: перечитанная подписка ещё
        // активна и действительно истекает в ближайшие ~25 часов.
        if (!$subscription->isActive() || !$subscription->ends_at->between(now(), now()->addHours(25))) {
            Log::info("SendSubscribeExpiringNotification: Подписка ID {$subscription->id} больше не требует уведомления.");
            return;
        }

        $user = $subscription->user;

        if (!$user) {
            return;
        }

        $user->notify(new SubscriptionExpiringSoonNotification(
            subscriptionId: $subscription->id,
            planName: $subscription->plan?->name ?? 'Подписка',
            price: $subscription->plan ? number_format($subscription->plan->price, 2) . ' ' . $subscription->plan->currency : null,
            endsAt: $subscription->ends_at->format('d.m.Y H:i'),
            isAutoRenew: (bool) $subscription->is_auto_renew
        ));

        Log::info("SendSubscribeExpiringNotification: Отправлено юзеру ID {$user->id} о подписке ID {$subscription->id}");
    }
}
