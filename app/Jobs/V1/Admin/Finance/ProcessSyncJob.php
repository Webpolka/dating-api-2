<?php

namespace App\Jobs\V1\Admin\Finance;

use App\Actions\V1\Admin\Finance\GrantSubscriptionAction;
use App\Events\V1\TransactionSynced;
use App\Models\AdminLog;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\V1\PaymentFailed;
use App\Notifications\V1\PaymentSuccessful;
use App\Services\V1\MockAcquiringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Асинхронная синхронизация статуса pending-транзакции с банком.
 *
 * FIX (пакет «Деньги», А3 + Б3):
 *  - Внешний вызов банка выполняется ВНЕ транзакции, без lockForUpdate:
 *    раньше запрос к банку (в моке — usleep 1с, с реальным банком — до
 *    таймаута) происходил внутри DB::transaction при заблокированной строке
 *    transactions → воркер держал лок и соединение с БД всю задержку.
 *  - Для транзакций-подписок план проверяется ДО markAsSuccess(): раньше
 *    при удалённом плане транзакция помечалась success, юзеру уходило
 *    «оплата успешна», а подписка не создавалась (else-ветки не было) —
 *    деньги списаны, выдачи нет, никто не узнает.
 *  - Выдача подписки — через GrantSubscriptionAction (единая логика
 *    продления/создания с блокировкой, см. docblock Action).
 *
 * Redis lock: TransactionController::sync() ставит лок 'transaction-sync-{id}'
 * на 60 сек и передаёт ВЛАДЕЛЬЦА лока в job (FIX: раньше restoreLock получал
 * 60 как owner — лок не освобождался никогда, release был мёртвым кодом).
 */
class ProcessSyncJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(
        public int $transactionId,
        public int $adminId,
        public string $lockOwner = '',
    ) {}

    public function handle(MockAcquiringService $bank): void
    {
        try {
            $admin = User::find($this->adminId);

            // ===== 1) Читаем транзакцию БЕЗ блокировки, зовём банк ВНЕ транзакции =====
            $snapshot = Transaction::with('user')->find($this->transactionId);

            if (!$snapshot) {
                Log::warning("SyncJob: Транзакция #{$this->transactionId} не найдена.");
                return;
            }

            if ($snapshot->status !== Transaction::STATUS_PENDING) {
                Log::warning("SyncJob: Транзакция #{$this->transactionId} уже не в статусе pending (текущий: {$snapshot->status}).");
                return;
            }

            $bankResponse = $bank->checkStatus($snapshot);

            // ===== 2) Применяем результат в короткой транзакции с блокировкой =====
            DB::transaction(function () use ($bankResponse, $admin): void {
                $transaction = Transaction::with('user')->lockForUpdate()->find($this->transactionId);

                // За время запроса к банку статус мог измениться (sync из
                // другого места / refund) — ничего не делаем.
                if (!$transaction || $transaction->status !== Transaction::STATUS_PENDING) {
                    Log::warning("SyncJob: Статус #{$this->transactionId} изменился за время запроса к банку — пропускаем.");
                    return;
                }

                if (($bankResponse['status'] ?? null) === 'success') {
                    $this->applySuccess($transaction, $bankResponse, $admin);
                    return;
                }

                $this->applyFailure($transaction, $bankResponse['message'] ?? 'Банк отклонил платеж.', $admin, $bankResponse);
            });
        } finally {
            $this->releaseSyncLock();
        }
    }

    private function applySuccess(Transaction $transaction, array $bankResponse, ?User $admin): void
    {
        // FIX А3: для подписочной транзакции план проверяем ДО markAsSuccess.
        // Плана нет → это провал: деньги не «сгорают» в success без выдачи.
        $plan = null;
        if ($transaction->type === Transaction::TYPE_SUBSCRIPTION) {
            $plan = SubscriptionPlan::find($transaction->meta['plan_id'] ?? null);
            if (!$plan) {
                $this->applyFailure($transaction, 'План подписки не найден (удалён?)', $admin, $bankResponse);
                return;
            }
        }

        $transaction->markAsSuccess([
            'synced_by' => $admin?->name ?? 'system',
            'bank_message' => $bankResponse['message'],
            'provider_transaction_id' => $bankResponse['provider_transaction_id'] ?? null,
        ]);

        if ($transaction->user) {
            if ($transaction->type === Transaction::TYPE_CREDITS && $transaction->credits_amount) {
                $balance = $transaction->user->balance()->firstOrCreate([]);
                $balance->addCredits($transaction->credits_amount);
            } elseif ($transaction->type === Transaction::TYPE_SUBSCRIPTION && $plan) {
                // Продление/создание с блокировкой — см. GrantSubscriptionAction
                app(GrantSubscriptionAction::class)->execute($transaction->user, $plan, $transaction);
            }

            $transaction->user->notify(new PaymentSuccessful(
                $transaction->id,
                (float) $transaction->amount,
                $transaction->type
            ));
        }

        $before = ['status' => $transaction->getOriginal('status')];
        AdminLog::record('transaction.sync_success', $transaction, $admin, $before, [
            'status'    => 'success',
            'synced_by' => $admin?->id,
            'context'   => [
                'transaction_id'  => $transaction->id,
                'user_id'         => $transaction->user_id,
                'amount'          => $transaction->amount,
                'type'            => $transaction->type,
                'provider_id'     => $bankResponse['provider_transaction_id'] ?? null,
            ],
        ], participants: [$transaction->user_id]);

        Log::info("SyncJob: Транзакция #{$transaction->id} подтверждена банком.");

        event(new TransactionSynced(
            $transaction,
            true,
            $bankResponse['message'] ?? 'Платеж подтвержден банком.'
        ));
    }

    private function applyFailure(Transaction $transaction, string $message, ?User $admin, array $bankResponse = []): void
    {
        $transaction->markAsFailed($message);

        if ($transaction->user) {
            $transaction->user->notify(new PaymentFailed(
                $transaction->id,
                (float) $transaction->amount,
                $message
            ));
        }

        $before = ['status' => $transaction->getOriginal('status')];
        AdminLog::record('transaction.sync_failed', $transaction, $admin, $before, [
            'status'       => 'failed',
            'bank_message' => $message,
            'context'      => [
                'transaction_id' => $transaction->id,
                'user_id'         => $transaction->user_id,
                'amount'          => $transaction->amount,
                'type'            => $transaction->type,
            ],
        ], participants: [$transaction->user_id]);

        Log::info("SyncJob: Транзакция #{$transaction->id} отклонена банком: {$message}");

        event(new TransactionSynced($transaction, false, $message));
    }

    /**
     * Освобождаем Redis lock (владелец передан из контроллера).
     * restoreLock(name, OWNER) — вторым аргументом идёт ВЛАДЕЛЕЦ, не TTL
     * (раньше туда передавали 60 — release не совпадал с владельцем
     * и был no-op, лок всегда доживал до 60 сек).
     */
    private function releaseSyncLock(): void
    {
        if ($this->lockOwner === '') {
            return;
        }

        try {
            Cache::restoreLock("transaction-sync-{$this->transactionId}", $this->lockOwner)->release();
        } catch (\Throwable $e) {
            // Лок с TTL 60 сек истечёт сам — это не ошибка.
            Log::warning("SyncJob: не удалось освободить лок #{$this->transactionId}: {$e->getMessage()}");
        }
    }

    public function failed(?\Throwable $e): void
    {
        Log::critical("SyncJob: синхронизация #{$this->transactionId} упала после {$this->tries} попыток. Транзакция осталась pending — требуется повторный sync вручную.", [
            'transaction_id' => $this->transactionId,
            'exception'      => $e?->getMessage(),
        ]);
    }
}
