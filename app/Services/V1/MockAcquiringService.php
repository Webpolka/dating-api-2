<?php

namespace App\Services\V1;

use App\Models\Transaction;

/**
 * Симуляция банка-эквайринга.
 *
 * ПРАВИЛО (FIX пакета «Деньги»): все методы этого сервиса имитируют ВНЕШНИЙ
 * вызов (задержка + недетерминированный ответ), поэтому вызывать их нужно
 * ВНЕ DB-транзакций — до открытия transaction/lockForUpdate. Иначе воркер
 * держит строку и соединение с БД всю «задержку банка» (в моке — секунды,
 * с реальным банком — до таймаута в 30-60с).
 *
 * Ставки и задержка — в config/services.php (block 'mock_acquiring'):
 * в тестах ставим rate=100/0 и latency_ms=0 — и флоу становится
 * детерминированным (это закрывает «rand() мешает тестам»).
 */
class MockAcquiringService
{
    private int $paymentSuccessRate;
    private int $refundSuccessRate;
    private int $syncSuccessRate;
    private int $latencyMs;

    public function __construct(
        ?int $paymentSuccessRate = null,
        ?int $refundSuccessRate = null,
        ?int $syncSuccessRate = null,
        ?int $latencyMs = null,
    ) {
        $this->paymentSuccessRate = $paymentSuccessRate ?? (int) config('services.mock_acquiring.payment_success_rate', 90);
        $this->refundSuccessRate  = $refundSuccessRate  ?? (int) config('services.mock_acquiring.refund_success_rate', 90);
        $this->syncSuccessRate    = $syncSuccessRate    ?? (int) config('services.mock_acquiring.sync_success_rate', 80);
        $this->latencyMs          = $latencyMs          ?? (int) config('services.mock_acquiring.latency_ms', 1000);
    }

    /**
     * Имитация сетевой задержки ответа банка.
     */
    private function sleep(): void
    {
        if ($this->latencyMs > 0) {
            usleep($this->latencyMs * 1000);
        }
    }

    /**
     * Решение «банка» по новому платежу (пополнение кредитов / подписка).
     * Используется payment-job'ами ДО открытия транзакции: одна попытка —
     * одно решение, ретраи job'а переигрывают ответ честно (как в реальном
     * мире: повторный запрос в банк — новый ответ).
     */
    public function approvePayment(): bool
    {
        $this->sleep();

        return random_int(1, 100) <= $this->paymentSuccessRate;
    }

    /**
     * Симуляция запроса на возврат средств в банк-эквайринг.
     * В реальной жизни здесь был бы HTTP-запрос (Guzzle/Http) к API Stripe/ЮKassa.
     *
     * @param Transaction $transaction
     * @return array
     */
    public function refund(Transaction $transaction): array
    {
        // 1. Имитируем сетевую задержку ответа от банка
        $this->sleep();

        // 2. Симулируем ответ
        $bankApproved = random_int(1, 100) <= $this->refundSuccessRate;

        if ($bankApproved) {
            // Успешный ответ от банка
            return [
                'success'      => true,
                'message'      => 'Возврат успешно обработан банком.',
                'provider_refund_id' => 'MOCK_REFUND_' . strtoupper(uniqid()),
                'raw_response' => [
                    'status'  => 'succeeded',
                    'amount'  => $transaction->amount,
                    'currency' => $transaction->currency,
                    'created' => now()->toIso8601String()
                ]
            ];
        }

        // Симулируем случайную ошибку банка
        $errors = [
            'Недостаточно средств на корр. счете мерчанта.',
            'Срок возврата истек (прошло более 30 дней).',
            'Карта плательщика заблокирована банком-эмитентом.',
            'Превышен лимит на возвраты в сутки.'
        ];

        $error = $errors[array_rand($errors)];

        return [
            'success'      => false,
            'message'      => $error,
            'provider_refund_id' => null,
            'raw_response' => [
                'status'     => 'failed',
                'error_code'  => 'bank_declined',
                'error_description' => $error,
                'created'    => now()->toIso8601String()
            ]
        ];
    }

    /**
     * Симуляция запроса статуса платежа в банк-эквайринг.
     * Используется саппортом для "подтолкнуть" зависшие pending транзакции.
     */
    public function checkStatus(Transaction $transaction): array
    {
        // Имитируем сетевую задержку
        $this->sleep();

        $isPaid = random_int(1, 100) <= $this->syncSuccessRate;

        if ($isPaid) {
            return [
                'success' => true,
                'status'  => 'success',
                'message' => 'Банк подтвердил успешную оплату.',
                'provider_transaction_id' => 'MOCK_PAY_' . strtoupper(uniqid()),
            ];
        }

        return [
            'success' => false,
            'status'  => 'failed',
            'message' => 'Банк отклонил платеж (недостаточно средств).',
        ];
    }
}
