<?php

namespace App\Actions\V1\Admin\Finance;

use App\Enums\V1\UserEventType;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserEvent;
use App\Models\UserSubscription;

/**
 * Выдача подписки после успешной оплаты — единая точка правды.
 *
 * Используется из ProcessSubscriptionPaymentGateway и ProcessSyncJob
 * (FIX пакета «Деньги», А4 + А5).
 *
 * КОНТРАКТ: выполняется ТОЛЬКО внутри DB::transaction() при уже
 * заблокированной (lockForUpdate) строке $transaction.
 *
 * ЧТО ЗАКРЫВАЕТ:
 *
 * 1. ГОНКА RENEW (А4): раньше job блокировал только transactions, а
 *    UserSubscription читал/писал без блокировки. Два параллельных продления
 *    читали одинаковый ends_at и оба писали «base + duration» → юзер платил
 *    дважды, продление получал одно. Теперь строка подписки берётся
 *    SELECT ... FOR UPDATE: второй job ждёт коммита первого, видит уже
 *    продлённый ends_at и продлевает от него — периоды складываются.
 *
 * 2. TOCTOU ПОКУПКИ (А5): раньше проверка «нельзя тот же tier» была только
 *    в контроллере, в job её не было → двойной клик / curl-обход = две
 *    UserSubscription и перезапись users.*_expires_at датой now+duration
 *    (оплаченный ранее срок мог СОКРАТИТЬСЯ). Теперь в job: есть действующая
 *    подписка tier → продлеваем её (деньги не пропадают, двойного периода
 *    нет); нет → создаём новую. users.*_expires_at обновляет ТОЛЬКО
 *    UserSubscriptionObserver (он берёт max(ends_at) по tier) — руками
 *    больше не трогаем.
 *
 * 3. ОТКАТ ВОЗВРАТОМ (А6): для продления пишем в meta транзакции
 *    renewed_subscription_id / renewed_days / renew_previous_ends_at —
 *    по ним RevokePurchasedItems откатывает именно эту оплату
 *    (вычитает продлённые дни), а не ищет подписку по transaction_id,
 *    которого у продления нет.
 */
class GrantSubscriptionAction
{
    public function execute(User $user, SubscriptionPlan $plan, Transaction $transaction): UserSubscription
    {
        // Действующая подписка того же tier (active или canceled — canceled
        // тоже даёт доступ до конца оплаченного периода). Блокируем строку:
        // параллельные payment-job'ы одного юзера сериализуются здесь.
        $currentSub = UserSubscription::query()
            ->where('user_id', $user->id)
            ->where('tier', $plan->tier)
            ->whereIn('status', [UserSubscription::STATUS_ACTIVE, UserSubscription::STATUS_CANCELED])
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at') // при исторических дублях продлеваем самую дальнюю
            ->lockForUpdate()
            ->first();

        // ===== ПРОДЛЕНИЕ =====
        if ($currentSub) {
            $previousEndsAt = $currentSub->ends_at->copy();
            $baseEndsAt = $previousEndsAt->isFuture() ? $previousEndsAt : now();
            $newEndsAt = $baseEndsAt->copy()->addDays($plan->duration_days);

            $currentSub->update([
                'ends_at'       => $newEndsAt,
                // auto_renew из текущего запроса (если передан), иначе прежний
                'is_auto_renew' => (bool) ($transaction->meta['auto_renew'] ?? $currentSub->is_auto_renew),
                // если подписка была canceled — возобновляем: юзер только что заплатил
                'status'        => UserSubscription::STATUS_ACTIVE,
            ]);

            // Аудит-след для отката возвратом (см. RevokePurchasedItems)
            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], [
                    'renewed_subscription_id' => $currentSub->id,
                    'renewed_days'            => (int) $plan->duration_days,
                    'renew_previous_ends_at'  => $previousEndsAt->toIso8601String(),
                ]),
            ]);

            // users.*_expires_at обновит UserSubscriptionObserver (saved) —
            // он же отправит уведомление при полном истечении. Явной записи
            // в users здесь НЕТ (раньше именно она перетирала max(ends_at)).
            $this->logPurchaseEvent($user, $plan, $transaction, isRenewal: true);
            return $currentSub;
        }

        // ===== НОВАЯ ПОДПИСКА =====
        $startsAt = now();
        $endsAt = $startsAt->copy()->addDays($plan->duration_days);
        
        $subscription = UserSubscription::create([
            'user_id'        => $user->id,
            'plan_id'        => $plan->id,
            'transaction_id' => $transaction->id,
            'tier'           => $plan->tier,
            'starts_at'      => $startsAt,
            'ends_at'        => $endsAt,
            'is_auto_renew'  => (bool) ($transaction->meta['auto_renew'] ?? false),
            'status'         => UserSubscription::STATUS_ACTIVE,
        ]);

        $this->logPurchaseEvent($user, $plan, $transaction, isRenewal: false);
        return $subscription;
    }

    /**
     * Логирование покупки/продления подписки в user_events.
     *
     * Событие: vip_purchased / premium_purchased (тип берётся из tier плана).
     * properties: days, price, transaction_id, is_renewal — для аккордеона
     * «Показать детали» в events-табе админки.
     *
     * Вызывается внутри DB::transaction() — insert попадёт в ту же транзакцию
     * и откатится вместе с ней при сбое (атомарность).
     */
    private function logPurchaseEvent(User $user, SubscriptionPlan $plan, Transaction $transaction, bool $isRenewal): void
    {
        $eventType = $plan->tier === SubscriptionPlan::TIER_VIP
            ? UserEventType::VipPurchased
            : UserEventType::PremiumPurchased;

        UserEvent::log($user, $eventType->value, [
            'days'            => (int) $plan->duration_days,
            'price'           => (float) $transaction->amount,
            'transaction_id'  => $transaction->id,
            'is_renewal'      => $isRenewal,
        ]);
    }
} 