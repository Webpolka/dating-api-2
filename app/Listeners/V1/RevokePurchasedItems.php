<?php

namespace App\Listeners\V1;

use App\Events\V1\TransactionRefunded;
use App\Models\Transaction;
use App\Models\UserSubscription;
use Illuminate\Contracts\Queue\ShouldQueueAfterCommit;
use Illuminate\Support\Facades\Log;

/**
 * Отбираем у юзера то, что он купил (Premium, VIP или кредиты).
 *
 * FIX (пакет «Деньги», А6):
 *
 * 1) AFTER COMMIT: листенер теперь ждёт коммита транзакции возврата
 *    (ProcessRefundJob). Раньше queued-листенер мог стартовать на другом
 *    воркере ДО коммита: если внешняя транзакция откатывалась (например,
 *    исключение в AdminLog после markAsRefunded) — у юзера уже отобрали
 *    подписку/кредиты, а деньги не вернулись (status остался success).
 *
 * 2) ОТКАТ ПРОДЛЕНИЯ: возврат renewal-платежа раньше не находил подписку
 *    (search по transaction_id, которого у продления нет) → юзер получал
 *    И деньги, И продлённый период. Теперь при продлении в meta транзакции
 *    пишется renewed_subscription_id + renewed_days (см.
 *    GrantSubscriptionAction) — возврат вычитает продлённые дни.
 *
 * 3) КРЕДИТЫ БЕЗ МИНУСА: раньше decrement('credits', N) на колонке с
 *    CHECK (credits >= 0) падал (23514), если юзер успел их потратить →
 *    листенер падал после ретраев, кредиты оставались (двойная выгода).
 *    Теперь условное списание spendCredits() (WHERE credits >= N);
 *    недостача фиксируется в meta транзакции для финразбора.
 * ПРИМЕЧАНИЕ: интерфейс ShouldHandleAfterCommit существовал только в Laravel
 * 11-12; в Laravel 13 он удалён, используется ShouldQueueAfterCommit
 * (наследует ShouldQueue + Dispatcher ставит job-у afterCommit = true).
 */
class RevokePurchasedItems implements ShouldQueueAfterCommit
{
    public function handle(TransactionRefunded $event): void
    {
        $transaction = $event->transaction;
        $user = $transaction->user;

        if (! $user) {
            return;
        }

        // ============================================
        // 1. ОТКАТ ПОДПИСКИ (Premium или VIP)
        // ============================================
        if ($transaction->type === Transaction::TYPE_SUBSCRIPTION) {

            // 1а. Обычная покупка: подписка создана этой транзакцией.
            $subscription = UserSubscription::where('transaction_id', $transaction->id)->first();

            if ($subscription) {
                // Полная отмена: observer сам обнулит users.*_expires_at
                // и отправит пуш об истечении.
                $subscription->update([
                    'status' => UserSubscription::STATUS_CANCELED,
                    'ends_at' => now(),
                    'canceled_at' => now(),
                    'is_auto_renew' => false,
                ]);
                Log::info("Подписка #{$subscription->id} ({$subscription->tier}) аннулирована из-за возврата #{$transaction->id}");

                return;
            }

            // 1б. Продление: транзакция продлевала существующую подписку.
            // Признак — meta.renewed_subscription_id (ставит GrantSubscriptionAction).
            $renewedId = $transaction->meta['renewed_subscription_id'] ?? null;
            $renewedDays = (int) ($transaction->meta['renewed_days'] ?? 0);

            if ($renewedId && $renewedDays > 0) {
                $subscription = UserSubscription::find($renewedId);

                if ($subscription && $subscription->ends_at && $subscription->ends_at->isFuture()) {
                    // Вычитаем именно дни этой оплаты: корректно даже при
                    // нескольких последовательных продлениях (каждый возврат
                    // снимает свой период, а не «откатывает к дате до всех»).
                    $restoredEndsAt = $subscription->ends_at->copy()->subDays($renewedDays);

                    // Если после вычитания период в прошлом — подписка
                    // фактически исчерпана: ставим now() (observer обнулит
                    // users.*_expires_at; ProcessSubscriptions закроет статус).
                    if (! $restoredEndsAt->isFuture()) {
                        $restoredEndsAt = now();
                    }

                    $subscription->update([
                        'ends_at' => $restoredEndsAt,
                        'is_auto_renew' => false,
                    ]);

                    Log::info("Продление по возврату #{$transaction->id} откатано: подписка #{$subscription->id} укорочена на {$renewedDays} дн. (новый ends_at: {$restoredEndsAt->toIso8601String()})");
                }
            }

            return;
        }

        // ============================================
        // 2. ОТКАТ КРЕДИТОВ
        // ============================================
        if ($transaction->type === Transaction::TYPE_CREDITS && $transaction->credits_amount) {
            $balance = $user->balance;

            if (! $balance) {
                return;
            }

            // Условное списание (WHERE credits >= N): не может увести баланс
            // в минус и не падает на CHECK-констрейнте user_balances.
            $revoked = $balance->spendCredits((int) $transaction->credits_amount);

            if ($revoked) {
                Log::info("Списано {$transaction->credits_amount} кредитов у юзера ID {$user->id} по возврату #{$transaction->id}");

                return;
            }

            // Юзер уже потратил начисленные кредиты: минус не делаем (CHECK
            // credits >= 0), долг не навязываем. Фиксируем недостачу в meta
            // транзакции — финразбор/компенсация решаются вручную.
            $transaction->update([
                'meta' => array_merge($transaction->meta ?? [], [
                    'revoke_shortfall' => (int) $transaction->credits_amount,
                    'revoke_shortfall_at' => now()->toIso8601String(),
                ]),
            ]);

            Log::warning(
                "Возврат #{$transaction->id}: у юзера ID {$user->id} недостаточно кредитов для ревока ".
                "(нужно {$transaction->credits_amount}, есть {$balance->credits}). Недостача зафиксирована в meta."
            );
        }
    }
}
