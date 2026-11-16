<?php

namespace App\Jobs\V1\Admin\Finance;

use App\Events\V1\TransactionRefunded;
use App\Events\V1\TransactionRefundFailed;
use App\Models\AdminLog;
use App\Models\Transaction;
use App\Models\User;
use App\Notifications\V1\RefundProcessed;
use App\Notifications\V1\PaymentFailed;
use App\Services\V1\MockAcquiringService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Оформление возврата: вызов банка + применение результата.
 *
 * FIX (пакет «Деньги», Б3 + Г3):
 *  - Внешний вызов банка ($bank->refund, в моке usleep ~1.5с, с реальным
 *    банком — до таймаута) выполняется ВНЕ DB-транзакции: раньше он шел
 *    внутри transaction при lockForUpdate строки transactions → воркер
 *    держал лок и соединение всю задержку банка.
 *  - Результат применяется в короткой транзакции с повторной проверкой
 *    статуса (за время звонка всё могло измениться).
 *  - failed(): после исчерпания ретраев помечаем refund_status=failed,
 *    чтобы админ видел состояние и мог повторить возврат (не «initiated»
 *    навсегда).
 */
class ProcessRefundJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public array $backoff = [10, 30];

    public function __construct(
        public int $transactionId,
        public int $adminId
    ) {}

    public function handle(MockAcquiringService $bank): void
    {
        $admin = User::find($this->adminId);

        // ===== 1) Читаем транзакцию БЕЗ блокировки, зовём банк ВНЕ транзакции =====
        $snapshot = Transaction::with('user')->find($this->transactionId);

        if (!$snapshot || $snapshot->status !== Transaction::STATUS_SUCCESS) {
            Log::warning("RefundJob: Транзакция #{$this->transactionId} не найдена, уже возвращена или не успешна.");
            return;
        }

        $bankResponse = $bank->refund($snapshot);

        // ===== 2) Применяем результат в короткой транзакции с блокировкой =====
        DB::transaction(function () use ($bankResponse, $admin): void {
            $transaction = Transaction::with('user')->lockForUpdate()->find($this->transactionId);

            if (!$transaction || $transaction->status !== Transaction::STATUS_SUCCESS) {
                // За время звонка в банк статус изменился (повторный refund
                // из другого запроса и т.п.) — ничего не делаем.
                Log::warning("RefundJob: Статус #{$this->transactionId} изменился за время запроса к банку — пропускаем.");
                return;
            }

            // ============================================
            // ОТКАЗ — банк отклонил возврат
            // ============================================
            if (!$bankResponse['success']) {
                // Помечаем refund_status = 'failed' — это позволит админу
                // повторить возврат позже (TransactionController::refund()
                // пропускает 'initiated' и 'success', но пускает 'failed').
                $transaction->markRefundFailed($bankResponse['message']);

                // Оповещаем юзера, что банк отклонил возврат
                if ($transaction->user) {
                    $transaction->user->notify(new PaymentFailed($transaction->id, (float)$transaction->amount, 'Банк отклонил возврат: ' . $bankResponse['message']));
                }

                // Логируем провал в AdminLog (для аудита).
                $before = [
                    'status'        => $transaction->getOriginal('status'),
                    'refund_status' => Transaction::REFUND_STATUS_INITIATED,
                ];
                AdminLog::record('transaction.refund_failed', $transaction, $admin, $before, [
                    'refund_status'  => Transaction::REFUND_STATUS_FAILED,
                    'bank_message'   => $bankResponse['message'],
                    'context'        => [
                        'transaction_id' => $transaction->id,
                        'user_id'        => $transaction->user_id,
                        'amount'         => $transaction->amount,
                        'type'           => $transaction->type,
                        'initiated_by'   => $admin?->id,
                    ],
                ], participants: [$transaction->user_id]);

                Log::error("RefundJob: Банк отклонил возврат #{$transaction->id}. Причина: {$bankResponse['message']}");

                // уведомляем админку через Reverb
                event(new TransactionRefundFailed($transaction, $bankResponse['message']));

                return;
            }

            // ============================================
            // УСПЕХ — банк подтвердил возврат
            // ============================================
            $metaData = [
                'bank_response' => $bankResponse['raw_response'],
                'bank_refund_id' => $bankResponse['provider_refund_id'],
            ];

            // Сохраним reason/comment ДО markAsRefunded — они уже в meta
            // (поставлены в TransactionController::refund), но markAsRefunded
            // делает array_merge, так что не потеряются.
            $refundReason = $transaction->meta['refund_reason'] ?? null;
            $refundComment = $transaction->meta['refund_comment'] ?? null;

            // Модель Transaction сама вызовет событие TransactionRefunded
            // внутри markAsRefunded (queued-листенер RevokePurchasedItems
            // ждёт коммита — ShouldQueueAfterCommit).
            $transaction->markAsRefunded($metaData);

            // Логируем успех в AdminLog (для аудита).
            $before = [
                'status'        => Transaction::STATUS_SUCCESS,
                'refund_status' => Transaction::REFUND_STATUS_INITIATED,
            ];
            AdminLog::record('transaction.refund_success', $transaction, $admin, $before, [
                'status'        => Transaction::STATUS_REFUNDED,
                'refund_status' => Transaction::REFUND_STATUS_SUCCESS,
                'reason'        => $refundReason,
                'comment'       => $refundComment,
                'context'       => [
                    'transaction_id'   => $transaction->id,
                    'user_id'          => $transaction->user_id,
                    'amount'           => $transaction->amount,
                    'type'             => $transaction->type,
                    'bank_refund_id'   => $bankResponse['provider_refund_id'] ?? null,
                    'initiated_by'     => $admin?->id,
                ],
            ], participants: [$transaction->user_id]);

            // Отправляем уведомление об успешном возврате
            if ($transaction->user) {
                $reason = $refundReason ?? 'По решению администрации';
                $transaction->user->notify(new RefundProcessed($transaction->id, (float)$transaction->amount, $reason));
            }

            Log::info("RefundJob: Возврат #{$transaction->id} успешно обработан банком.");
        });
    }

    /**
     * Финальный провал после всех ретраев.
     *
     * Помечаем refund_status=failed (не оставляем 'initiated' навсегда):
     * админ увидит состояние в карточке транзакции и сможет повторить.
     * Если и здесь БД недоступна — только critical-лог (лока нет, всё
     * безопасно: статус транзакции не менялся).
     */
    public function failed(?\Throwable $e): void
    {
        Log::critical("RefundJob: возврат #{$this->transactionId} упал после {$this->tries} попыток.", [
            'transaction_id' => $this->transactionId,
            'exception'      => $e?->getMessage(),
        ]);

        try {
            Transaction::find($this->transactionId)?->markRefundFailed(
                'Внутренняя ошибка обработки возврата. Повторите попытку.'
            );
        } catch (\Throwable $markError) {
            Log::critical("RefundJob: не удалось пометить refund_status=failed для #{$this->transactionId}: {$markError->getMessage()}");
        }
    }
}
