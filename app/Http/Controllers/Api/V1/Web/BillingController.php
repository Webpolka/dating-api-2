<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Jobs\V1\Admin\Finance\ProcessCreditPaymentGateway;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

class BillingController extends Controller
{
    /**
     * Пополнение кредитов.
     *
     * FIX (пакет «Деньги», В5) — идемпотентность оплаты:
     *  1) Заголовок Idempotency-Key: повторный запрос с тем же ключом
     *     возвращает СУЩЕСТВУЮЩУЮ транзакцию (тот же redirect_url), не
     *     создавая новую. Фронт может смело ретраить при обрывах сети.
     *  2) Анти-даблклик без ключа: если для этого юзера уже есть pending-
     *     транзакция того же номинала, созданная менее 60 сек назад —
     *     возвращаем её (двойное списание при двойном клике невозможно).
     *
     * FIX (безопасность): в ответе при сбое БД больше нет $e->getMessage() —
     * наружу фиксированный текст, детали в лог.
     */
    public function pay(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'amount' => 'required|in:80,250,500,900',
            'payment_method' => 'required|in:card,yoomoney',
            'auto_top_up' => 'boolean',
            'return_url' => 'nullable|string'
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        // Idempotency-Key — опциональный заголовок (uuid или любая строка до 255)
        $idempotencyKey = $request->header('Idempotency-Key');
        if ($idempotencyKey !== null && strlen($idempotencyKey) > 255) {
            return response()->json(['errors' => ['idempotency_key' => ['Заголовок Idempotency-Key не должен превышать 255 символов.']]], 422);
        }

        $validated = $validator->validated();
        $user = $request->user();

        $plans = [
            80 => ['credits' => 80, 'bonus' => 0],
            250 => ['credits' => 300, 'bonus' => 50],
            500 => ['credits' => 650, 'bonus' => 150],
            900 => ['credits' => 1250, 'bonus' => 350],
        ];

        $plan = $plans[$validated['amount']];
        $creditsToCharge = $plan['credits'] + $plan['bonus'];

        // ===== ИДЕМПОТЕНТНОСТЬ: повтор с тем же ключом =====
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = Transaction::where('user_id', $user->id)
                ->where('type', Transaction::TYPE_CREDITS)
                ->where('meta->idempotency_key', $idempotencyKey)
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                return response()->json([
                    'success' => true,
                    'transaction_id' => $existing->id,
                    'redirect_url' => config('app.frontend_url') . '/billing/success?transaction_id=' . $existing->id,
                    'idempotent_replay' => true,
                ]);
            }
        }

        // ===== АНТИ-ДАБЛКЛИК: живая pending-транзакция того же номинала (< 60 сек) =====
        $recentPending = Transaction::where('user_id', $user->id)
            ->where('type', Transaction::TYPE_CREDITS)
            ->where('status', Transaction::STATUS_PENDING)
            ->where('amount', $validated['amount'])
            ->where('created_at', '>=', now()->subMinute())
            ->orderByDesc('id')
            ->first();

        if ($recentPending) {
            return response()->json([
                'success' => true,
                'transaction_id' => $recentPending->id,
                'redirect_url' => config('app.frontend_url') . '/billing/success?transaction_id=' . $recentPending->id,
                'idempotent_replay' => true,
            ]);
        }

        try {
            $meta = [
                'auto_top_up' => $validated['auto_top_up'] ?? false,
                'bonus_credits' => $plan['bonus'],
                'return_url' => $validated['return_url'] ?? '/',
            ];

            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $meta['idempotency_key'] = $idempotencyKey;
            }

            $transaction = Transaction::create([
                'user_id' => $user->id,
                'amount' => $validated['amount'],
                'currency' => 'RUB',
                'type' => Transaction::TYPE_CREDITS,
                'status' => Transaction::STATUS_PENDING,
                'provider' => $validated['payment_method'],
                'credits_amount' => $creditsToCharge,
                'meta' => $meta,
            ]);

            // Запускаем симуляцию банка
            ProcessCreditPaymentGateway::dispatch($transaction->id)->delay(now()->addSeconds(5))->onQueue('payments');

            // Возвращаем URL для редиректа (фронтенд откроет его в новой вкладке)
            $confirmationUrl = config('app.frontend_url') . '/billing/success?transaction_id=' . $transaction->id;

            return response()->json([
                'success' => true,
                'transaction_id' => $transaction->id,
                'redirect_url' => $confirmationUrl
            ]);

        } catch (\Exception $e) {
            // Детали — в лог, наружу — фиксированный текст (без $e->getMessage())
            Log::error("BillingController: не удалось создать транзакцию пополнения для юзера #{$user->id}: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Не удалось создать платёж. Попробуйте ещё раз чуть позже.'
            ], 500);
        }
    }

    //  API для опроса статуса (Polling)
      public function status(Request $request, Transaction $transaction)
    {
        if ($transaction->user_id !== $request->user()->id) {
            abort(403);
        }

        return response()->json([
            'status' => $transaction->status,
            'creditsAmount' => $transaction->credits_amount,
            'failReason' => $transaction->meta['fail_reason'] ?? null,
            'returnUrl' => $transaction->meta['return_url'] ?? '/', // <--- Добавили возвратный URL
        ]);
    }
}
