<?php

namespace App\Http\Controllers\Api\V1\Admin\Main;

use App\Enums\V1\GiftCategory;
use App\Http\Requests\Api\V1\Admin\Main\UserFinancesRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserGiftsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserListRequest;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use App\Models\PromoCodeUsage;
use App\Models\Transaction;
use App\Models\User;
use App\Models\UserCard;
use App\Models\UserGift;
use App\Models\UserSubscription;
use Illuminate\Http\JsonResponse;

/**
 * Пер-юзер листинги: ФИНАНСЫ (табы «Финансы», «Подарки», «Карты»,
 * «Промокоды» страницы /users/{id}). Все — admin only (зеркально
 * глобальным разделам /finance/*).
 *
 * ПАКЕТ 6: выделен из монолита UserListingsController (1477 строк).
 * URL и форматы ответов — 1:1 с прежним кодом.
 */
class UserFinanceListingsController extends UserTabListingController
{
    // ============================================
    // ФИНАНСЫ юзера (admin only): balance + statuses + transactions + subscriptions
    // ============================================

    /**
     * GET /api/admin/users/{user}/finances?trans_page=1&sub_page=2
     *
     * Composite-ответ — «финансовый дашборд» юзера (зеркало blade-эталона):
     *   - balance        — credits + superlikes_remaining (UserBalance, скаляр).
     *   - statuses        — active Premium/VIP (premium_expires_at, vip_expires_at,
     *                       has_active_premium, has_active_vip).
     *   - transactions    — история транзакций, по 5 на страницу.
     *   - subscriptions    — история подписок, по 5 на страницу.
     *
     * Действия (sync/refund транзакций, manage подписок) — в глобальных разделах
     * /finance/transactions и /finance/subscriptions.
     */
    public function finances(UserFinancesRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        // withTrashed — чтобы видеть баланс/статусы даже у удалённых юзеров
        // (как везде в пер-юзер табах: финансы нужны для отчётов постфактум).
        $user = User::withTrashed()
            ->with('balance')
            ->findOrFail($userId);

        // === Баланс ===
        $balance = $user->balance;
        $balancePayload = [
            'credits'              => (int) ($balance?->credits ?? 0),
            'superlikes_remaining' => (int) ($balance?->superlikes_remaining ?? 0),
            'superlikes_reset_at'  => $balance?->superlikes_reset_at?->toIso8601String(),
        ];

        // === Активные статусы (Premium / VIP) ===
        $statusesPayload = [
            'has_active_premium'  => (bool) $user->has_active_premium,
            'premium_expires_at'  => $user->premium_expires_at?->toIso8601String(),
            'has_active_vip'      => (bool) $user->has_active_vip,
            'vip_expires_at'      => $user->vip_expires_at?->toIso8601String(),
        ];

        // === Транзакции (по 5 на страницу, как в blade) ===
        $transactions = Transaction::where('user_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'trans_page', $request->transPage());

        // === Подписки (по 5 на страницу) ===
        $subscriptions = UserSubscription::where('user_id', $userId)
            ->with(['plan:id,tier,name,price,currency,duration_days', 'transaction:id'])
            ->latest('starts_at')
            ->latest('id')
            ->paginate(5, ['*'], 'sub_page', $request->subPage());

        return response()->json([
            'balance'       => $balancePayload,
            'statuses'      => $statusesPayload,
            'transactions'  => $this->paginatorToArray($transactions, fn(Transaction $t) => $this->formatTransaction($t)),
            'subscriptions' => $this->paginatorToArray($subscriptions, fn(UserSubscription $s) => $this->formatSubscription($s)),
        ]);
    }

    /** Копия TransactionController::formatTransaction() минус поле user. */
    private function formatTransaction(Transaction $t): array
    {
        $statusBadge = $t->status_badge;

        $tier = $t->meta['tier'] ?? null;
        $typeBadge = match ($t->type) {
            'subscription' => [
                'variant'   => 'outline',
                'label'     => $tier === 'vip' ? 'VIP' : 'Premium',
                'className' => $tier === 'vip'
                    ? 'bg-sky-500/15 text-sky-600 border-sky-500/30 hover:bg-sky-500/20 dark:text-sky-400 dark:border-sky-500/40'
                    : 'bg-orange-500/15 text-orange-600 border-orange-500/30 hover:bg-orange-500/20 dark:text-orange-400 dark:border-orange-500/40',
            ],
            'credits' => [
                'variant'   => 'secondary',
                'label'     => 'Кредиты',
                'className' => '',
            ],
            'refund' => [
                'variant'   => 'destructive',
                'label'     => 'Возврат',
                'className' => '',
            ],
            default => [
                'variant'   => 'secondary',
                'label'     => $t->type,
                'className' => '',
            ],
        };

        $amountClass = match ($t->status) {
            'refunded' => 'text-destructive font-medium',
            'failed'   => 'text-muted-foreground/50',
            'pending'  => 'text-muted-foreground',
            default    => 'text-green-500 font-medium',
        };

        return [
            'id'                      => $t->id,
            'user_id'                 => $t->user_id,
            'amount'                  => (float) $t->amount,
            'formatted_amount'        => $t->formatted_amount,
            'amount_class'            => $amountClass,
            'currency'                => $t->currency,
            'type'                    => $t->type,
            'type_badge'              => $typeBadge,
            'status'                  => $t->status,
            'status_badge'            => $statusBadge,
            'provider'                => $t->provider,
            'provider_transaction_id' => $t->provider_transaction_id,
            'credits_amount'          => $t->credits_amount,
            'created_at'              => $t->created_at?->toIso8601String(),
            'updated_at'              => $t->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Формат строки подписки (UserSubscription) в пер-юзер табе «Финансы».
     *
     * Зеркало blade-эталона: tier-бейдж (VIP/Premium), название тарифа,
     * срок (starts_at/ends_at), ссылка на чек (transaction_id), статус-бейдж
     * + дата события (created_at для active, updated_at для отменённых/истёкших).
     * status_badge — из модели UserSubscription::getStatusBadgeAttribute().
     */
    private function formatSubscription(UserSubscription $s): array
    {
        $statusBadge = $s->status_badge;
        $plan = $s->plan;

        return [
            'id'              => $s->id,
            'plan_id'         => $s->plan_id,
            'transaction_id'  => $s->transaction_id,
            'tier'            => $s->tier,
            'status'          => $s->status,
            'status_badge'    => $statusBadge,
            'is_auto_renew'   => (bool) $s->is_auto_renew,
            'starts_at'       => $s->starts_at?->toIso8601String(),
            'ends_at'         => $s->ends_at?->toIso8601String(),
            'created_at'      => $s->created_at?->toIso8601String(),
            'updated_at'      => $s->updated_at?->toIso8601String(),
            'plan' => $plan ? [
                'id'            => $plan->id,
                'tier'          => $plan->tier,
                'name'          => $plan->name,
                'price'         => (float) $plan->price,
                'currency'      => $plan->currency,
                'duration_days' => (int) $plan->duration_days,
            ] : null,
        ];
    }

    // ============================================
    // ФИНАНСЫ: подарки юзера — sent/received (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/gifts?filter=all&page=2
     *
     * Единый список подарков юзера с фильтром по направлению (all|sent|received).
     * В каждом ряду добавлено поле `direction` ('sent' | 'received') — фронт
     * по нему рисует стрелку направления и выбирает «counterpart» (получателя
     * для sent, отправителя для received).
     *
     * Формат айтема 1:1 с GiftController::formatUserGift() + direction.
     * Действия (hide/restore) — через глобальный раздел /finance/gifts.
     */
    public function gifts(UserGiftsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $filter = $request->filter('all');

        $query = UserGift::withTrashed()
            ->with([
                'sender'   => $this->badgeUser(),
                'receiver' => $this->badgeUser(),
                'gift:id,name,image_url,category,type',
            ])
            ->when($filter === 'sent', fn($q) => $q->where('sender_id', $userId))
            ->when($filter === 'received', fn($q) => $q->where('receiver_id', $userId))
            ->when($filter === 'all', function ($q) use ($userId) {
                // «Все» — и отправленные, и полученные. Дедупликация не нужна:
                // UserGift не может иметь sender_id = receiver_id (валидация на запись).
                $q->where(function ($sq) use ($userId) {
                    $sq->where('sender_id', $userId)->orWhere('receiver_id', $userId);
                });
            })
            ->latest('created_at')
            ->latest('id');

        $gifts = $query->paginate(15, ['*'], 'page', $request->page());

        // Счётчики по направлениям — для бейджей на кнопках фильтра.
        $counts = [
            'all'      => UserGift::where(function ($q) use ($userId) {
                $q->where('sender_id', $userId)->orWhere('receiver_id', $userId);
            })->count(),
            'sent'     => UserGift::where('sender_id', $userId)->count(),
            'received' => UserGift::where('receiver_id', $userId)->count(),
        ];

        return response()->json(
            $this->paginatorToArray($gifts, fn(UserGift $ug) => $this->formatUserGift($ug, $userId)) + [
                'counts' => $counts,
                'filter' => $filter,
            ]
        );
    }

    /**
     * Копия GiftController::formatUserGift() + поле `direction`.
     *
     * direction = 'sent' если юзер — отправитель, иначе 'received'.
     * В режиме filter=all в одном списке могут быть оба направления, поэтому
     * фронт читает direction из каждого ряда, а не из query-параметра.
     */
    private function formatUserGift(UserGift $ug, int $userId): array
    {
        $catEnum = $ug->gift ? GiftCategory::tryFrom($ug->gift->category ?? '') : null;
        $direction = $ug->sender_id === $userId ? 'sent' : 'received';

        return [
            'id'                 => $ug->id,
            'direction'          => $direction,
            'snapshot_name'      => $ug->snapshot_name,
            'snapshot_image_url' => $ug->image_url,
            'snapshot_price'     => (int) $ug->snapshot_price,
            'message'            => $ug->message,
            'is_private'         => (bool) $ug->is_private,
            'is_read'            => (bool) $ug->is_read,
            'is_hidden'          => $ug->trashed(),
            'created_at'         => $ug->created_at?->toIso8601String(),
            'sender'   => $ug->sender ? AdminUserBadgeResource::nullable($ug->sender) : null,
            'receiver' => $ug->receiver ? AdminUserBadgeResource::nullable($ug->receiver) : null,
            'gift' => $ug->gift ? [
                'id'             => $ug->gift->id,
                'name'           => $ug->gift->name,
                'image_url'      => $ug->gift->image_url,
                'type'           => $ug->gift->type,
                'category'       => $ug->gift->category,
                'category_label' => $catEnum?->label(),
                'category_color' => $catEnum?->color(),
            ] : null,
        ];
    }

    // ============================================
    // ФИНАНСЫ: платёжные карты юзера (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/cards?page=1
     *
     * user_cards с softDeletes (критично для фин. отчётов — удалённая
     * карта остаётся в истории). Read-only: токены карт не возвращаем —
     * только маскированные данные (last4, brand, срок).
     */
    public function cards(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $cards = UserCard::withTrashed()
            ->where('user_id', $userId)
            ->orderByDesc('is_default')
            ->latest('created_at')
            ->paginate(15, ['*'], 'page', $request->page());

        return response()->json($this->paginatorToArray($cards, fn(UserCard $c) => [
            'id'            => $c->id,
            'gateway'       => $c->gateway,
            'card_type'     => $c->card_type,
            'last4'         => $c->last4,
            'expiry_month'  => $c->expiry_month,
            'expiry_year'   => $c->expiry_year,
            'is_active'     => (bool) $c->is_active,
            'is_default'    => (bool) $c->is_default,
            'is_expired'    => $c->isExpired(),
            'trashed'       => $c->trashed(),
            'created_at'    => $c->created_at?->toIso8601String(),
            'deleted_at'    => $c->deleted_at?->toIso8601String(),
        ]));
    }

    // ============================================
    // ФИНАНСЫ: промокоды юзера (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/promocodes?page=1
     *
     * История применений промокодов (promo_code_usages).
     * applied_discount — снапшот скидки в рублях на момент применения.
     */
    public function promocodes(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $usages = PromoCodeUsage::query()
            ->where('user_id', $userId)
            ->with([
                'promoCode:id,code,discount_type,discount_value,duration_days,max_uses,used_count,expires_at,is_active',
                'transaction:id,amount,status,type,created_at',
            ])
            ->latest('created_at')
            ->latest('id')
            ->paginate(15, ['*'], 'page', $request->page());

        return response()->json($this->paginatorToArray($usages, fn(PromoCodeUsage $u) => [
            'id'              => $u->id,
            'applied_discount' => (float) $u->applied_discount,
            'created_at'      => $u->created_at?->toIso8601String(),
            'promo_code' => $u->promoCode ? [
                'id'             => $u->promoCode->id,
                'code'           => $u->promoCode->code,
                'discount_type'  => $u->promoCode->discount_type,
                'discount_value' => (float) $u->promoCode->discount_value,
                'duration_days'  => $u->promoCode->duration_days,
                'expires_at'     => $u->promoCode->expires_at?->toIso8601String(),
                'is_active'      => (bool) $u->promoCode->is_active,
            ] : null,
            'transaction' => $u->transaction ? [
                'id'         => $u->transaction->id,
                'amount'     => (float) $u->transaction->amount,
                'status'     => $u->transaction->status,
                'type'       => $u->transaction->type,
                'created_at' => $u->transaction->created_at?->toIso8601String(),
            ] : null,
        ]));
    }
}
