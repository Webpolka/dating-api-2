<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Enums\V1\PromoCodeType;
use App\Http\Controllers\Controller;
use App\Http\Requests\Api\V1\Web\RedeemPromoCodeRequest;
use App\Http\Requests\Api\V1\Web\ValidatePromoCodeRequest;
use App\Models\PromoCode;
use App\Models\SubscriptionPlan;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Web API промокодов для юзера.
 *
 * Два endpoint'а (оба под web + auth:sanctum + throttle:api-promo-code):
 *
 * 1. POST /api/subscriptions/validate-promo { code, plan_id }
 *    Валидирует код ДО оплаты. Возвращает детали скидки.
 *    Используется фронтом в форме оплаты: юзер вводит код → фронт показывает
 *    "Скидка 50%, итого 250₽" (для percent/fixed) ИЛИ
 *    "Код даёт 30 дней VIP бесплатно" (для premium/vip — кнопка меняется на
 *    "Активировать").
 *
 * 2. POST /api/promo-codes/redeem { code }
 *    Активация premium/vip кода — создаёт UserSubscription без транзакции
 *    (бесплатные дни). Для percent/fixed — 422.
 *
 * Применение percent/fixed кода при оплате — в SubscriptionController::pay()
 * (через поле promo_code в PaySubscriptionRequest) и в job'е
 * ProcessSubscriptionPaymentGateway (через PromoCode::apply после успеха).
 */
class PromoCodeController extends Controller
{
    /**
     * POST /api/subscriptions/validate-promo
     *
     * Возвращает:
     *  - valid: bool
     *  - discount_type, discount_type_label
     *  - Для percent/fixed: discount_value, calculated_discount, final_amount, original_amount.
     *  - Для premium/vip: duration_days, tier (даёт бесплатную подписку).
     *  - reason: null если valid, иначе код причины ('not_found', 'inactive',
     *    'expired', 'fully_used', 'not_for_user', 'already_used').
     */
    public function validate(ValidatePromoCodeRequest $request): JsonResponse
    {
        $user = $request->user();
        $code = $request->validated()['code'];
        $planId = (int) $request->validated()['plan_id'];

        $promo = PromoCode::findByCode($code);

        if (!$promo) {
            return $this->invalidResponse('not_found', 'Промокод не найден.');
        }

        if (!$promo->is_active) {
            return $this->invalidResponse('inactive', 'Промокод деактивирован.');
        }

        if ($promo->isExpired()) {
            return $this->invalidResponse('expired', 'Срок действия промокода истёк.');
        }

        if ($promo->isFullyUsed()) {
            return $this->invalidResponse('fully_used', 'Лимит использований промокода исчерпан.');
        }

        if ($promo->isPersonal() && $promo->user_id !== $user->id) {
            return $this->invalidResponse('not_for_user', 'Этот промокод не предназначен вашему аккаунту.');
        }

        if ($promo->usages()->where('user_id', $user->id)->exists()) {
            return $this->invalidResponse('already_used', 'Вы уже использовали этот промокод.');
        }

        $type = PromoCodeType::tryFrom($promo->discount_type);
        $plan = SubscriptionPlan::find($planId);

        // Базовый ответ — общий для всех типов.
        $response = [
            'valid'               => true,
            'reason'              => null,
            'promo_code_id'       => $promo->id,
            'code'                => $promo->code,
            'discount_type'       => $promo->discount_type,
            'discount_type_label' => PromoCodeType::labelFor($promo->discount_type),
            'gives_free_days'     => $type?->givesFreeDays() ?? false,
        ];

        // Type-specific поля.
        if ($type?->givesFreeDays()) {
            // premium/vip — бесплатные дни. Фронт меняет кнопку на "Активировать N дней TIER".
            return response()->json(array_merge($response, [
                'tier'          => $promo->discount_type, // 'premium' или 'vip'
                'duration_days' => $promo->duration_days,
                'message'       => "Код даёт {$promo->duration_days} дней " . ($promo->discount_type === 'vip' ? 'VIP' : 'Premium') . " бесплатно.",
            ]));
        }

        // percent/fixed — скидка на оплату.
        $originalAmount = (float) $plan->price;
        $calculatedDiscount = $promo->calculateDiscount($originalAmount);
        $finalAmount = max(0, $originalAmount - $calculatedDiscount);

        return response()->json(array_merge($response, [
            'discount_value'      => (float) $promo->discount_value,
            'original_amount'     => $originalAmount,
            'calculated_discount' => $calculatedDiscount,
            'final_amount'        => $finalAmount,
            'message'             => $calculatedDiscount > 0
                ? "Скидка применена: −{$calculatedDiscount} ₽, итого {$finalAmount} ₽."
                : 'Промокод принят, но скидка равна 0.',
        ]));
    }

    /**
     * POST /api/promo-codes/redeem
     *
     * Активация premium/vip кода — бесплатные дни подписки без оплаты.
     *
     * Создаёт UserSubscription:
     *  - tier = discount_type (premium/vip)
     *  - starts_at = now
     *  - ends_at = now + duration_days
     *  - status = active
     *  - transaction_id = null (бесплатно)
     *
     * Если у юзера уже есть активная подписка того же tier — продлеваем
     * (ends_at += duration_days), не сокращаем текущий период.
     *
     * Для percent/fixed — 422 "применяется при оплате подписки".
     */
    public function redeem(RedeemPromoCodeRequest $request): JsonResponse
    {
        $user = $request->user();
        $code = $request->validated()['code'];

        $promo = PromoCode::findByCode($code);

        if (!$promo) {
            throw ValidationException::withMessages([
                'code' => ['Промокод не найден.'],
            ]);
        }

        if (!$promo->isValidForUser($user)) {
            // Детальное сообщение — какой именно пункт провален.
            $reason = match (true) {
                !$promo->is_active       => 'Промокод деактивирован.',
                $promo->isExpired()      => 'Срок действия промокода истёк.',
                $promo->isFullyUsed()    => 'Лимит использований промокода исчерпан.',
                $promo->isPersonal() && $promo->user_id !== $user->id => 'Этот промокод не предназначен вашему аккаунту.',
                $promo->usages()->where('user_id', $user->id)->exists() => 'Вы уже использовали этот промокод.',
                default                  => 'Промокод недействителен.',
            };

            throw ValidationException::withMessages(['code' => [$reason]]);
        }

        $type = PromoCodeType::tryFrom($promo->discount_type);
        if (!$type || !$type->givesFreeDays()) {
            throw ValidationException::withMessages([
                'code' => ['Этот промокод применяется при оплате подписки. Введите его в форме покупки Premium/VIP.'],
            ]);
        }

        if (!$promo->duration_days) {
            Log::warning("PromoCode redeem: код #{$promo->id} ({$promo->code}) типа {$promo->discount_type} без duration_days — некорректная конфигурация.");
            throw ValidationException::withMessages([
                'code' => ['Промокод некорректно настроен. Обратитесь в поддержку.'],
            ]);
        }

        $tier = $promo->discount_type; // 'premium' или 'vip'
        $durationDays = (int) $promo->duration_days;

        // Транзакция: apply промокода (increment + usage) + создание/продление подписки.
        // apply сам по себе в транзакции, но мы оборачиваем с созданием подписки
        // — атомарность: либо оба, либо ничего.
        $result = DB::transaction(function () use ($promo, $user, $tier, $durationDays) {
            $usage = $promo->apply($user, null); // transaction_id = null

            if (!$usage) {
                throw new \RuntimeException('Не удалось применить промокод.');
            }

            // Найти текущую активную подписку того же tier — продлить если есть.
            $current = UserSubscription::where('user_id', $user->id)
                ->where('tier', $tier)
                ->whereIn('status', [UserSubscription::STATUS_ACTIVE, UserSubscription::STATUS_CANCELED])
                ->where('ends_at', '>', now())
                ->orderByDesc('ends_at')
                ->first();

            if ($current) {
                // Продлеваем: ends_at += duration_days (от текущего ends_at, не от now).
                $newEndsAt = $current->ends_at->addDays($durationDays);
                $current->update([
                    'ends_at' => $newEndsAt,
                    'status'  => UserSubscription::STATUS_ACTIVE, // отменённая → активная снова
                ]);

                return [
                    'action'      => 'extended',
                    'new_ends_at' => $newEndsAt->toIso8601String(),
                    'tier'        => $tier,
                    'days_added'  => $durationDays,
                ];
            }

            // Нет активной — создаём новую (без Transaction, transaction_id=null).
            $sub = UserSubscription::create([
                'user_id'     => $user->id,
                'plan_id'     => null, // не привязан к плану — это free-дни
                'transaction_id' => null,
                'tier'         => $tier,
                'starts_at'    => now(),
                'ends_at'      => now()->addDays($durationDays),
                'is_auto_renew' => false,
                'status'       => UserSubscription::STATUS_ACTIVE,
            ]);

            return [
                'action'      => 'created',
                'new_ends_at' => $sub->ends_at->toIso8601String(),
                'tier'        => $tier,
                'days_granted' => $durationDays,
            ];
        });

        // UserSubscriptionObserver обновит users.premium_expires_at/vip_expires_at.

        $tierLabel = $tier === 'vip' ? 'VIP' : 'Premium';
        $actionLabel = $result['action'] === 'extended'
            ? "Подписка {$tierLabel} продлена на {$durationDays} дней."
            : "Вам начислено {$durationDays} дней подписки {$tierLabel}.";

        return response()->json([
            'success'    => true,
            'message'    => $actionLabel,
            'tier'       => $result['tier'],
            'new_ends_at' => $result['new_ends_at'],
            'action'     => $result['action'],
        ]);
    }

    /**
     * Стандартизированный ответ для невалидного кода (validate endpoint).
     */
    private function invalidResponse(string $reason, string $message): JsonResponse
    {
        return response()->json([
            'valid'  => false,
            'reason' => $reason,
            'message' => $message,
        ], 422);
    }
}
