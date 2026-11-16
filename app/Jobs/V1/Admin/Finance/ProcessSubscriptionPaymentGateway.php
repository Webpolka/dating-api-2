<?php

namespace App\Jobs\V1\Admin\Finance;

use App\Actions\V1\Admin\Finance\GrantSubscriptionAction;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Services\V1\MockAcquiringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Симуляция ответа эквайринга для оплаты подписки.
 *
 * FIX (пакет «Деньги», А4 + А5 + Б3 + Г3):
 *  - Решение «банка» принимается ДО открытия транзакции: не держим
 *    блокировки/соединение во время внешнего вызова (в моке — миллисекунды,
 *    с реальным банком — до таймаута).
 *  - Вся выдача/продление — через GrantSubscriptionAction: внутри транзакции
 *    с lockForUpdate по подписке. Закрыты: гонка двойного renew (платил
 *    дважды — продлевалось один раз), TOCTOU покупки (проверка «тот же tier»
 *    была только в контроллере — двойной клик/curl давал две подписки и
 *    мог СОКРАТИТЬ оплаченный срок перезаписью users.*_expires_at).
 *  - План проверяется ДО markAsSuccess(): при удалённом плане транзакция
 *    честно фейлится — деньги не «сгорают» в статусе success без подписки.
 *  - Идемпотентность ретраев: guard по статусу pending — повторный запуск
 *    (после retry) выходит без повторной выдачи.
 */
class ProcessSubscriptionPaymentGateway implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Ретраи с нарастающим интервалом (сек). */
    public array $backoff = [10, 30];

    public function __construct(public int $transactionId) {}

    public function handle(MockAcquiringService $bank): void
    {
        // 1) Решение банка — ВНЕ транзакции, без блокировок (Б3).
        //    При ретрае job'а решение переигрывается — это честно:
        //    повторный запрос в банк = новый ответ.
        $approved = $bank->approvePayment();

        // 2) Применяем результат в короткой транзакции.
        DB::transaction(function () use ($approved): void {
            $transaction = Transaction::lockForUpdate()->find($this->transactionId);

            // Guard: нет транзакции или уже обработана (ретрай/дубль) — выходим.
            if (!$transaction || $transaction->status !== Transaction::STATUS_PENDING) {
                return;
            }

            $plan = SubscriptionPlan::find($transaction->meta['plan_id'] ?? null);

            if (!$plan) {
                // План удалён между оплатой и начислением — честно фейлим
                // транзакцию, деньги не «сгорают» в статусе success без подписки.
                $transaction->markAsFailed('План подписки не найден (удалён?)');
                return;
            }

            if (!$approved) {
                $transaction->markAsFailed("Ошибка: Недостаточно средств (Симуляция)");
                return;
            }

            $transaction->markAsSuccess();

            $user = $transaction->user;
            if (!$user) {
                // Юзер удалён между оплатой и job'ом: транзакция остаётся
                // success (деньги получены), подписку выдать некому.
                Log::warning("SubscriptionGateway: юзер транзакции #{$transaction->id} не найден — подписка не выдана.");
                return;
            }

            // Единая логика: есть действующая подписка tier → продлеваем,
            // нет → создаём. Перепроверка внутри транзакции с блокировкой
            // закрывает гонки независимо от проверок в контроллере.
            app(GrantSubscriptionAction::class)->execute($user, $plan, $transaction);
        });
    }

    /**
     * Финальный провал после всех ретраев.
     *
     * Транзакцию СПЕЦИАЛЬНО не трогаем: банк мог реально списать деньги,
     * а мы не знаем результат. Оставляем pending — админ через
     * TransactionController::sync() спросит банк и закроет руками.
     * Алерт уходит в лог уровня critical (мониторить канал!).
     */
    public function failed(?\Throwable $e): void
    {
        Log::critical("SubscriptionGateway: job оплаты #{$this->transactionId} упала после {$this->tries} попыток. Транзакция осталась pending — требуется sync вручную.", [
            'transaction_id' => $this->transactionId,
            'exception'      => $e?->getMessage(),
        ]);
    }
}
