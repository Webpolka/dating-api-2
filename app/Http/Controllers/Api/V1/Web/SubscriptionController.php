<?php

namespace App\Http\Controllers\Api\V1\Web;

use App\Http\Controllers\Controller;
use App\Jobs\V1\Admin\Finance\ProcessSubscriptionPaymentGateway;
use App\Models\PromoCode;
use App\Models\SubscriptionPlan;
use App\Models\Transaction;
use App\Models\UserSubscription;
use App\Enums\V1\PromoCodeType;
use App\Http\Requests\Api\V1\Web\PaySubscriptionRequest;
use App\Http\Requests\Api\V1\Web\SubscriptionPlansRequest;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class SubscriptionController extends Controller
{
    /**
     * API endpoint для получения списка тарифов подписок.
     *
     * Заменяет View::share('premiumPlans'/'vipPlans') из AppServiceProvider
     * — это работало для Blade, но SPA на Next.js не использует Blade,
     * ему нужны отдельные HTTP-запросы.
     *
     * Используется:
     *  - VipModal.tsx     — GET /api/subscriptions/plans?tier=vip
     *  - PremiumModal.tsx — GET /api/subscriptions/plans?tier=premium
     *
     * Без tier — вернёт все активные тарифы (premium + vip).
     */
    public function plans(SubscriptionPlansRequest $request)
    {
        $validated = $request->validated();
        $tier = $validated['tier'] ?? null;

        // Пакет 5 (рекомендация №8 — кэш справочников): тарифы меняются только
        // из админки, а дёргаются при каждом открытии Premium/VIP-модалки.
        // 10 минут кэша по ключу tier; инвалидация — SubscriptionPlan::booted()
        // (saved/deleted сбрасывают все три ключа: all/premium/vip).
        $cacheKey = 'subscription_plans:web:' . ($tier ?? 'all');

        $plans = Cache::remember($cacheKey, 600, function () use ($tier) {
            $query = SubscriptionPlan::active()->ordered();

            if ($tier !== null) {
                $query->where('tier', $tier);
            }

            // Явно отбираем поля, нужные фронту — не отдаём apple_product_id,
            // google_product_id, sort_order, is_active, timestamps и т.д.
            return $query->get()->map(fn(SubscriptionPlan $plan) => [
                'id'            => $plan->id,
                'tier'          => $plan->tier,
                'name'          => $plan->name,
                'slug'          => $plan->slug,
                // decimal:2 возвращает строку "250.00" — кастуем к float для фронта
                'price'         => (float) $plan->price,
                'old_price'     => $plan->old_price !== null ? (float) $plan->old_price : null,
                'currency'      => $plan->currency,
                'duration_days' => $plan->duration_days,
            ])->values();
        });

        return response()->json(['plans' => $plans]);
    }

    /**
     * Общая логика идемпотентности оплаты (FIX пакета «Деньги», В5).
     *
     * 1) Заголовок Idempotency-Key: повторный запрос с тем же ключом возвращает
     *    СУЩЕСТВУЮЩУЮ транзакцию (тот же redirect_url), не создавая новую.
     * 2) Анти-даблклик без ключа: живая pending-транзакция этого же плана,
     *    созданная менее 60 сек назад, — возвращаем её же.
     *
     * Возвращает готовый JsonResponse (replay/422) или null, если можно создавать новую.
     */
    private function findIdempotentReplay(Request $request, $user, int $planId): ?JsonResponse
    {
        $idempotencyKey = $request->header('Idempotency-Key');

        if ($idempotencyKey !== null && strlen($idempotencyKey) > 255) {
            return response()->json(['errors' => ['idempotency_key' => ['Заголовок Idempotency-Key не должен превышать 255 символов.']]], 422);
        }

        // Повтор с тем же ключом — отдаём существующую транзакцию
        if ($idempotencyKey !== null && $idempotencyKey !== '') {
            $existing = Transaction::where('user_id', $user->id)
                ->where('type', Transaction::TYPE_SUBSCRIPTION)
                ->where('meta->idempotency_key', $idempotencyKey)
                ->orderByDesc('id')
                ->first();

            if ($existing) {
                return response()->json($this->paymentResponse($existing, true));
            }
        }

        // Даблклик: pending по этому же плану моложе 60 секунд
        $recentPending = Transaction::where('user_id', $user->id)
            ->where('type', Transaction::TYPE_SUBSCRIPTION)
            ->where('status', Transaction::STATUS_PENDING)
            ->where('meta->plan_id', $planId)
            ->where('created_at', '>=', now()->subMinute())
            ->orderByDesc('id')
            ->first();

        if ($recentPending) {
            return response()->json($this->paymentResponse($recentPending, true));
        }

        return null;
    }

    /**
     * Валидация промокода для применения при оплате (percent/fixed).
     *
     * Возвращает массив с promo-данными для meta или JsonResponse с ошибкой.
     * Defence in depth: повторная проверка после /api/subscriptions/validate-promo.
     *
     * @return array{promo_code_id: int, promo_code_discount: float, final_amount: float}|JsonResponse
     */
    private function resolvePromoCodeForPayment($user, SubscriptionPlan $plan, ?string $code): array|JsonResponse
    {
        if (!$code) {
            // Промокода нет — стандартная оплата.
            return [
                'promo_code_id'      => 0,
                'promo_code_discount' => 0.0,
                'final_amount'       => (float) $plan->price,
            ];
        }

        $promo = PromoCode::findByCode($code);
        if (!$promo) {
            return response()->json([
                'success' => false,
                'message' => 'Промокод не найден.',
            ], 422);
        }

        if (!$promo->isValidForUser($user)) {
            $reason = match (true) {
                !$promo->is_active    => 'деактивирован',
                $promo->isExpired()   => 'срок действия истёк',
                $promo->isFullyUsed() => 'лимит использований исчерпан',
                $promo->isPersonal() && $promo->user_id !== $user->id => 'не предназначен вашему аккаунту',
                default               => 'уже использован',
            };
            return response()->json([
                'success' => false,
                'message' => "Промокод недействителен: {$reason}.",
            ], 422);
        }

        $type = PromoCodeType::tryFrom($promo->discount_type);
        if (!$type || $type->givesFreeDays()) {
            // premium/vip — это не скидка на оплату, а бесплатные дни.
            // Юзер должен использовать /api/promo-codes/redeem.
            return response()->json([
                'success' => false,
                'message' => 'Этот промокод даёт бесплатные дни подписки. Активируйте его через раздел «Мои промокоды» в настройках профиля.',
            ], 422);
        }

        $originalAmount = (float) $plan->price;
        $discount = $promo->calculateDiscount($originalAmount);
        $finalAmount = max(0, $originalAmount - $discount);

        return [
            'promo_code_id'      => $promo->id,
            'promo_code_discount' => $discount,
            'final_amount'       => $finalAmount,
        ];
    }

    /**
     * Единый формат ответа «платёж принят» (redirect на SPA success page).
     */
    private function paymentResponse(Transaction $transaction, bool $replay = false): array
    {
        $confirmationUrl = config('app.frontend_url')
            . '/subscriptions/success?transaction_id=' . $transaction->id;

        return [
            'success' => true,
            'transaction_id' => $transaction->id,
            'redirect_url' => $confirmationUrl,
            'idempotent_replay' => $replay,
        ];
    }

    /**
     * Активная (или отменённая, но ещё действующая) подписка юзера того же tier.
     *
     * Пакет 5 (рекомендация №6 — дедупликация pay/renew): общий поиск для
     * обоих методов — раньше два почти идентичных query-builder'а.
     * Берём самую «дальнюю» по ends_at — edge case нескольких исторических
     * записей покрыт.
     */
    private function findCurrentSubscription($user, string $tier): ?UserSubscription
    {
        return UserSubscription::where('user_id', $user->id)
            ->where('tier', $tier)
            ->whereIn('status', [UserSubscription::STATUS_ACTIVE, UserSubscription::STATUS_CANCELED])
            ->where('ends_at', '>', now())
            ->orderByDesc('ends_at')
            ->first();
    }

    /**
     * Человекопонятное имя tier для сообщений об ошибке.
     * match() вместо ucfirst() — «vip» должен показываться как «VIP».
     */
    private function tierLabel(string $tier): string
    {
        return match ($tier) {
            SubscriptionPlan::TIER_PREMIUM => 'Премиум',
            SubscriptionPlan::TIER_VIP      => 'VIP',
            default                         => ucfirst($tier),
        };
    }

    /**
     * ОБЩЕЕ для pay() и renew() (Пакет 5, рекомендация №6): идемпотентность
     * (FIX В5 пакета «Деньги») + создание pending-транзакции + запуск
     * платёжного job'а. Раньше эти ~50 строк были копипастой в обоих методах.
     *
     * Порядок проверок сохранён 1:1 с исходными методами:
     * tier-гард вызывавшего метода → идемпотентность → создание.
     *
     * @param array<string, mixed> $extraMeta Доп. поля meta для renew()
     *                                        (action=renew, current_subscription_id).
     */
    private function createPaymentTransaction(
        PaySubscriptionRequest $request,
        $user,
        SubscriptionPlan $plan,
        array $validated,
        array $extraMeta = [],
    ): JsonResponse {
        // Идемпотентность / анти-даблклик (FIX В5): был ли уже принят такой платёж?
        if ($replay = $this->findIdempotentReplay($request, $user, $plan->id)) {
            return $replay;
        }

        // Промокод: если передан — валидируем и пересчитываем amount.
        // Возвращает либо [promo_code_id, promo_code_discount, final_amount],
        // либо JsonResponse с 422 — в последнем случае сразу возвращаем.
        $promoResolution = $this->resolvePromoCodeForPayment(
            $user,
            $plan,
            $validated['promo_code'] ?? null,
        );

        if ($promoResolution instanceof JsonResponse) {
            return $promoResolution;
        }

        try {
            $meta = array_merge([
                'plan_id' => $plan->id,
                'tier' => $plan->tier,
                'auto_renew' => $validated['auto_renew'] ?? false,
                // FIX: route('home') в API-приложении НЕ существует — при
                // отсутствии return_url это ловило RouteNotFoundException (500).
                // Дефолт — главная SPA.
                'return_url' => $validated['return_url'] ?? config('app.frontend_url'),
            ], $extraMeta);

            $idempotencyKey = $request->header('Idempotency-Key');
            if ($idempotencyKey !== null && $idempotencyKey !== '') {
                $meta['idempotency_key'] = $idempotencyKey;
            }

            // Промокод в meta — для job'а (после успеха вызвать PromoCode::apply)
            // и для фин. отчётов (applied discount).
            if ($promoResolution['promo_code_id'] > 0) {
                $meta['promo_code_id'] = $promoResolution['promo_code_id'];
                $meta['promo_code_discount'] = $promoResolution['promo_code_discount'];
                // Сохраняем original_amount (plan.price до скидки) — для UI
                // (колонка "Исходная" в таблице транзакций и per-user табе промокодов).
                // Фронт НЕ должен вычислять original = amount + discount —
                // это даёт неверный результат для percent (amount уже после скидки).
                $meta['original_amount'] = (float) $plan->price;
            }

            $transaction = Transaction::create([
                'user_id' => $user->id,
                // Итоговая сумма после скидки (если промокода нет — это plan->price).
                'amount' => $promoResolution['final_amount'],
                'currency' => $plan->currency,
                'type' => Transaction::TYPE_SUBSCRIPTION,
                'status' => Transaction::STATUS_PENDING,
                'provider' => $validated['payment_method'],
                'meta' => $meta,
            ]);

            // ЗАПУСКАЕМ АСИНХРОННУЮ СИМУЛЯЦИЮ БАНКА (Задержка 5 секунд)
            ProcessSubscriptionPaymentGateway::dispatch($transaction->id)
                ->delay(now()->addSeconds(5))
                ->onQueue('payments');

            return response()->json($this->paymentResponse($transaction));

        } catch (\Exception $e) {
            // Детали — в лог, наружу — фиксированный текст (FIX: утечка $e->getMessage())
            Log::error("SubscriptionController::pay/renew: сбой создания транзакции для юзера #{$user->id}: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Не удалось создать платёж. Попробуйте ещё раз чуть позже.'
            ], 500);
        }
    }

    public function pay(PaySubscriptionRequest $request)
    {
        // Пакет 5: правила — в PaySubscriptionRequest (у pay/renew они были
        // идентичными копиями), формат 422 — {"errors": {...}} как раньше.
        $validated = $request->validated();
        $user = $request->user();
        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

        // NEW: Защита от повторной покупки того же tier.
        // Это "defence in depth" — покрывает случай, когда пользователь вызовет
        // POST /api/subscriptions/pay напрямую через curl/Postman, минуя
        // фронтовую защиту в vip-page-client.tsx / premium-page-client.tsx
        // (где при активной подписке показывается 404 / NotFoundPlaceholder).
        //
        // ЛОГИКА (соответствует LovePlanet, Mamba, Twinby и другим dating-платформам):
        //  - Premium и VIP — НЕ взаимоисключающие. У пользователя может быть
        //    ОБА одновременно (это разные "уровни привилегий": Premium даёт
        //    общение без ограничений и расширенный поиск, VIP даёт приоритет
        //    в выдаче и выделение в поиске).
        //  - Нельзя купить ТОТ ЖЕ tier, если он уже активен (даже если подписка
        //    помечена как 'canceled' — она даёт доступ до конца оплаченного
        //    периода, ends_at > now).
        //  - После истечения подписки (ends_at < now, либо status='expired')
        //    пользователь может купить снова.
        //
        // Это правильное поведение: платформа не хочет дважды списывать деньги
        // за тот же период, и не хочет усложнять биллинг (как сложить два VIP?).
        if ($this->findCurrentSubscription($user, $plan->tier) !== null) {
            return response()->json([
                'success' => false,
                'message' => "У вас уже активна подписка {$this->tierLabel($plan->tier)}. Дождитесь окончания текущего периода, чтобы продлить.",
            ], 400);
        }

        return $this->createPaymentTransaction($request, $user, $plan, $validated);
    }

    /**
     * NEW: Продление уже активной подписки того же tier.
     *
     * В отличие от pay(), который отказывает при активной подписке того же tier,
     * renew() — наоборот, ТРЕБУЕТ активную (или отменённую, но ещё действующую)
     * подписку. Иначе нет чего продлевать.
     *
     * После успешной оплаты job ProcessSubscriptionPaymentGateway НЕ создаёт
     * новую UserSubscription, а ПРОДЛЕВАЕТ текущую — прибавляет duration_days
     * плана к ends_at текущей подписки. Так пользователь не теряет уже
     * оплаченные дни (если осталось 5 дней и он продлевает на 30 дней —
     * ends_at сдвигается на 35 дней от сегодня, а не на 30).
     *
     * Endpoint: POST /api/subscriptions/renew
     * Body: { plan_id, payment_method, auto_renew, return_url }
     *
     * Защита: renew() работает ТОЛЬКО при активной подписке того же tier.
     * Если у пользователя подписка Premium, и он вызывает renew() с plan_id
     * для VIP — будет 400 ошибка "У вас нет активной подписки VIP для продления".
     * Это правильно — renew() всегда продлевает ТОТ ЖЕ tier, что и текущая
     * активная подписка.
     */
    public function renew(PaySubscriptionRequest $request)
    {
        // Пакет 5: правила — те же, что у pay() (были идентичной копией),
        // валидация — в PaySubscriptionRequest.
        $validated = $request->validated();
        $user = $request->user();
        $plan = SubscriptionPlan::findOrFail($validated['plan_id']);

        // Ищем активную (или отменённую, но действующую) подписку того же tier.
        // Берём самую "дальнюю" по ends_at — это та, которую надо продлить.
        // Сортировка orderByDesc('ends_at') покрывает edge case, если у пользователя
        // почему-то несколько записей (исторически сложилось) — продлеваем самую дальнюю.
        $currentSubscription = $this->findCurrentSubscription($user, $plan->tier);

        if (!$currentSubscription) {
            return response()->json([
                'success' => false,
                'message' => "У вас нет активной подписки {$this->tierLabel($plan->tier)} для продления. Используйте обычную покупку.",
            ], 400);
        }

        // Пакет 5 (дедупликация): общая ветка создания транзакции; renew-специфика
        // передаётся через meta (action=renew, current_subscription_id).
        return $this->createPaymentTransaction($request, $user, $plan, $validated, [
            // NEW: помечаем что это продление. ProcessSubscriptionPaymentGateway
            // (через GrantSubscriptionAction) продлит текущую подписку.
            'action' => 'renew',
            'current_subscription_id' => $currentSubscription->id,
        ]);
    }

    public function success(Request $request)
    {
        // В SPA этот метод не нужен — Next.js сам рендерит страницу /subscriptions/success.
        // Оставлен только если в routes/web.php ещё есть роут на этот метод (для
        // обратной совместимости со старым Blade). Можно удалить, когда полностью
        // перейдёшь на SPA.
        $transactionId = $request->query('transaction_id');
        $transaction = Transaction::findOrFail($transactionId);

        // FIX: route('home') не существует — только 500. Дефолт — главная SPA.
        $returnUrl = $transaction->meta['return_url'] ?? config('app.frontend_url');
        if (str_contains($returnUrl, '/premium') || str_contains($returnUrl, '/vip')) {
            $returnUrl = config('app.frontend_url');
        }

        return view('pages.subscriptions.success', [
            'transactionId' => $transaction->id,
            'tier' => $transaction->meta['tier'] ?? 'subscription',
            'returnUrl' => $returnUrl
        ]);
    }

    // API для опроса статуса (Polling)
    public function status(Request $request, Transaction $transaction)
    {
        // Проверка безопасности
        if ($transaction->user_id !== $request->user()->id) {
            abort(403);
        }

        $planName = SubscriptionPlan::find($transaction->meta['plan_id'] ?? null)?->name ?? 'тариф';
        $endsAt = null;
        if ($transaction->status === Transaction::STATUS_SUCCESS) {
            $sub = $transaction->user->subscriptions()->where('transaction_id', $transaction->id)->first();
            if ($sub) $endsAt = $sub->ends_at->format('d.m.Y H:i');
        }

        return response()->json([
            'status' => $transaction->status,
            'planName' => $planName,
            'endsAt' => $endsAt,
            'failReason' => $transaction->meta['fail_reason'] ?? null,
            // tier нужен фронту для выбора цветовой схемы (blue для VIP, orange для Premium)
            'tier' => $transaction->meta['tier'] ?? 'premium',
            // returnUrl нужен фронту для кнопки "Продолжить" на success page
            'returnUrl' => $transaction->meta['return_url'] ?? null,
        ]);
    }
}
