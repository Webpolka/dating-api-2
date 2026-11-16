<?php

namespace App\Jobs\V1\Admin\Finance;

use App\Models\Transaction;
use App\Models\UserBalance;
use App\Services\V1\MockAcquiringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Симуляция ответа эквайринга для пополнения кредитов.
 *
 * FIX (пакет «Деньги», Б3 + Г3):
 *  - Решение «банка» — ДО транзакции, без блокировок (см. MockAcquiringService).
 *  - markAsSuccess() + increment('credits') в ОДНОЙ короткой транзакции
 *    с lockForUpdate (как и раньше): крах между шагами невозможен.
 *  - lockForUpdate также защищает от двойной обработки при ретрае job'ы:
 *    параллельный воркер заблокируется на чтении, увидит уже не-PENDING
 *    статус и выйдет.
 *  - Идемпотентность ретраев: guard по статусу pending.
 */
class ProcessCreditPaymentGateway implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    /** Ретраи с нарастающим интервалом (сек). */
    public array $backoff = [10, 30];

    public function __construct(public int $transactionId) {}

    public function handle(MockAcquiringService $bank): void
    {
        // 1) Решение банка — ВНЕ транзакции (Б3).
        $approved = $bank->approvePayment();

        // 2) Применяем результат в короткой транзакции.
        DB::transaction(function () use ($approved): void {
            $transaction = Transaction::lockForUpdate()->find($this->transactionId);

            if (!$transaction || $transaction->status !== Transaction::STATUS_PENDING) {
                return;
            }

            if (!$approved) {
                $transaction->markAsFailed("Ошибка: Недостаточно средств (Симуляция)");
                return;
            }

            // 1. Отмечаем транзакцию успешной
            $transaction->markAsSuccess();

            // 2. Железобетонное начисление кредитов (в той же транзакции)
            $balance = UserBalance::firstOrCreate(
                ['user_id' => $transaction->user_id],
                ['credits' => 0] // Если записи нет, создаем с нулем
            );

            // Инкремент — атомарен на уровне БД
            $balance->increment('credits', $transaction->credits_amount);
        });
    }

    /**
     * Финальный провал после всех ретраев.
     *
     * Транзакцию не трогаем (банк мог списать деньги): остаётся pending,
     * закрывается вручную через TransactionController::sync().
     */
    public function failed(?\Throwable $e): void
    {
        Log::critical("CreditGateway: job оплаты #{$this->transactionId} упала после {$this->tries} попыток. Транзакция осталась pending — требуется sync вручную.", [
            'transaction_id' => $this->transactionId,
            'exception'      => $e?->getMessage(),
        ]);
    }
}
