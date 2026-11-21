<?php

namespace App\Http\Controllers\Api\V1\Admin\Moderation;

use App\Actions\V1\Admin\Moderation\ModerateVerificationAction;
use App\Http\Controllers\Api\V1\Admin\AdminController;
use App\Http\Requests\Api\V1\Admin\Moderation\RejectVerificationRequest;
use App\Http\Resources\Api\V1\Admin\Moderation\VerificationModerationCardResource;
use App\Http\Resources\Api\V1\Admin\Moderation\VerificationResource;
use App\Models\User;
use App\Models\Verification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Модерация верификаций пользователей (Admin API).
 *
 * Архитектура 1:1 как PhotoController:
 *  - Controller: валидация, resolution модели + 404, форматирование через Resource.
 *  - ModerateVerificationAction: меняет статусы, пишет AdminLog, шлёт уведомления.
 *
 * Два режима index():
 *  - queue: status=pending без поиска → pending заявки с user+photo eager-load,
 *    пагинация по Verification (UNIQUE partial гарантирует один pending на юзера,
 *    поэтому группировка по юзеру, как в PhotoController, не нужна).
 *  - grid: history (approved/rejected) или поиск → плоский список с фильтром по status.
 *
 * Действия:
 *  - approve(id) → Action::approve (ставит user.is_verified=true, шлёт notification).
 *  - reject(id, reason) → Action::reject.
 *
 * Удаление/восстановление не предусмотрены: заявка — single-shot, после решения
 * остаётся в истории. Чтобы перевыпустить — юзер подаёт новую (UNIQUE разрешает,
 * т.к. old теперь approved/rejected, не pending).
 */
class VerificationController extends AdminController
{
    public function index(Request $request): JsonResponse
    {
        $status = $request->string('status', 'pending')->toString();
        $search = $request->string('q', '')->toString();
        $perPage = $status === 'pending'
            ? min(max((int) $request->integer('per_page', 10), 1), 50)
            : min(max((int) $request->integer('per_page', 24), 1), 100);
        $page = max((int) $request->integer('page', 1), 1);

        if (!in_array($status, ['pending', 'approved', 'rejected'], true)) {
            $status = 'pending';
        }

        $searchOperator = config('database.default') === 'pgsql' ? 'ilike' : 'like';
        $isSearching = !empty(trim($search));
        $isIdSearch = $isSearching && ctype_digit($search);

        // Умный авто-detect статуса при поиске по ID
        if ($isIdSearch) {
            $verification = Verification::find((int) $search);
            if ($verification && $verification->status !== $status) {
                $status = $verification->status;
            }
        }

        // Общий eager-load для обоих режимов: user (badge) + photo (предпросмотр).
        $eagerLoad = [
            'user' => fn($q) => $q->withTrashed()
                ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                ->withAvatar(),
            'photo' => fn($q) => $q->select(['id', 'user_id', 'type', 'status', 'is_primary', 'path_thumb', 'path_medium', 'path_large', 'path_original']),
        ];

        $query = Verification::query()
            ->with($eagerLoad)
            ->whereHas('user', fn($q) => $q->withTrashed()->excludeStaff());

        // Фильтр по статусу
        $query->where('status', $status);

        // Поиск
        if ($isSearching) {
            $query->where(function ($q) use ($search, $searchOperator, $isIdSearch) {
                if ($isIdSearch) {
                    $q->where('id', (int) $search)
                        ->orWhere('user_id', (int) $search)
                        ->orWhere('code', (int) $search);
                } else {
                    $q->whereHas('user', fn($sub) => $sub->withTrashed()->where('name', $searchOperator, "%{$search}%"));
                }
            });
        }

        // Сортировка: при поиске по ID — поднимаем точное совпадение наверх.
        $query->when($isIdSearch, fn($q) => $q->orderByRaw('CASE WHEN id = ? THEN 0 ELSE 1 END', [(int) $search]))
            ->latest()->latest('id');

        $verifications = $query->paginate($perPage, ['*'], 'page', $page);

        // Queue mode — используется VerificationModerationCardResource
        // (упрощённая структура без moderator, для очереди).
        // Grid mode — полная VerificationResource (с moderator).
        $resourceClass = ($status === 'pending' && !$isSearching)
            ? VerificationModerationCardResource::class
            : VerificationResource::class;

        return response()->json([
            'mode'   => ($status === 'pending' && !$isSearching) ? 'queue' : 'grid',
            'data'   => $resourceClass::collection($verifications->getCollection())->resolve($request),
            'meta'   => [
                'current_page' => $verifications->currentPage(),
                'last_page'    => $verifications->lastPage(),
                'per_page'     => $verifications->perPage(),
                'total'        => $verifications->total(),
                'from'         => $verifications->firstItem(),
                'to'           => $verifications->lastItem(),
            ],
            'stats'  => $this->getCounts(),
            'status' => $status,
        ]);
    }

    public function counts(): JsonResponse
    {
        return response()->json(['stats' => $this->getCounts()]);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $verification = Verification::with([
            'user' => fn($q) => $q->withTrashed()
                ->select(['id', 'name', 'email', 'role', 'status', 'premium_expires_at', 'vip_expires_at', 'is_verified', 'last_seen', 'deleted_at'])
                ->withAvatar(),
            'photo' => fn($q) => $q->select(['id', 'user_id', 'type', 'status', 'is_primary', 'path_thumb', 'path_medium', 'path_large', 'path_original']),
            'moderator' => fn($q) => $q->select(['id', 'name', 'email', 'role', 'status']),
        ])->find($id);

        if (!$verification) {
            return $this->notFound('Заявка');
        }

        return response()->json([
            'data' => (new VerificationResource($verification))->resolve($request),
        ]);
    }

    // ============================================
    // ДЕЙСТВИЯ МОДЕРАЦИИ — делегируют ModerateVerificationAction
    // ============================================

    public function approve(Request $request, int $id, ModerateVerificationAction $action): JsonResponse
    {
        $verification = Verification::find($id);
        if (!$verification) {
            return $this->notFound('Заявка');
        }

        $action->approve($verification, $request->user());

        return $this->message('Заявка одобрена. Аккаунт верифицирован.');
    }

    public function reject(RejectVerificationRequest $request, int $id, ModerateVerificationAction $action): JsonResponse
    {
        $verification = Verification::find($id);
        if (!$verification) {
            return $this->notFound('Заявка');
        }

        $action->reject($verification, $request->user(), $request->validated()['reason']);

        return $this->message('Заявка отклонена');
    }

    // ============================================
    // ХЕЛПЕРЫ
    // ============================================

    private function getCounts(): array
    {
        $stats = Verification::query()
            ->whereHas('user', fn($q) => $q->withTrashed()->excludeStaff())
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
                SUM(CASE WHEN status = 'approved' THEN 1 ELSE 0 END) as approved,
                SUM(CASE WHEN status = 'rejected' THEN 1 ELSE 0 END) as rejected
            ")->first();

        return [
            'total'    => (int) ($stats->total ?? 0),
            'pending'  => (int) ($stats->pending ?? 0),
            'approved' => (int) ($stats->approved ?? 0),
            'rejected' => (int) ($stats->rejected ?? 0),
        ];
    }
}
