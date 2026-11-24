<?php

namespace App\Http\Controllers\Api\V1\Admin\Main;

use App\Http\Requests\Api\V1\Admin\Main\UserAuthLogsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserBroadcastsRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserListRequest;
use App\Models\AdminLog;
use App\Models\Broadcast;
use App\Models\User;
use App\Models\UserAuthLog;
use App\Support\V1\AdminLogMeta;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Str;

/**
 * Пер-юзер листинги: АУДИТ (табы «Рассылки», «Логи админов», «История
 * входов» страницы /users/{id}). Все — admin only.
 *
 * ПАКЕТ 6: выделен из монолита UserListingsController (1477 строк).
 * URL и форматы ответов — 1:1 с прежним кодом.
 */
class UserAuditListingsController extends UserTabListingController
{
    // ============================================
    // РАССЫЛКИ, адресованные юзеру (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/broadcasts?filter=all&page=2
     *
     * Все рассылки, адресованные юзеру — и персональные (где в JSON
     * target_audience есть user_id = userId), и массовые (target_audience
     * пуст/null → рассылались «всем», юзер тоже получил). Фильтр по каналу:
     * all | in_app | push | email. Управление — в /system/broadcasts.
     *
     * message отдаём целиком (не Str::limit) — фронт делает line-clamp и
     * показывает полный текст в title-атрибуте (зеркало blade-эталона).
     */
    public function broadcasts(UserBroadcastsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $filter = $request->filter('all');

        $broadcasts = Broadcast::query()
            ->where(function ($query) use ($userId) {
                // 1. Персональные рассылки (target_audience->user_id = userId).
                $query->where('target_audience->user_id', $userId)
                    // 2. Массовые рассылки для всех (target_audience не задан).
                    ->orWhere(function ($q) {
                        $q->whereNull('target_audience')
                            ->orWhere('target_audience', '[]')
                            ->orWhere('target_audience', '{}');
                    });
            })
            ->when($filter !== 'all', fn($q) => $q->where('type', $filter))
            ->with(['admin:id,name'])
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'page', $request->page());

        // Счётчики по каналам — для бейджей на кнопках фильтра.
        $counts = [
            'all'    => Broadcast::where(function ($query) use ($userId) {
                $query->where('target_audience->user_id', $userId)
                    ->orWhere(function ($q) {
                        $q->whereNull('target_audience')
                            ->orWhere('target_audience', '[]')
                            ->orWhere('target_audience', '{}');
                    });
            })->count(),
            'in_app' => Broadcast::where('type', Broadcast::TYPE_IN_APP)
                ->where(function ($query) use ($userId) {
                    $query->where('target_audience->user_id', $userId)
                        ->orWhere(function ($q) {
                            $q->whereNull('target_audience')
                                ->orWhere('target_audience', '[]')
                                ->orWhere('target_audience', '{}');
                        });
                })->count(),
            'email'  => Broadcast::where('type', Broadcast::TYPE_EMAIL)
                ->where(function ($query) use ($userId) {
                    $query->where('target_audience->user_id', $userId)
                        ->orWhere(function ($q) {
                            $q->whereNull('target_audience')
                                ->orWhere('target_audience', '[]')
                                ->orWhere('target_audience', '{}');
                        });
                })->count(),
            'push'   => Broadcast::where('type', Broadcast::TYPE_PUSH)
                ->where(function ($query) use ($userId) {
                    $query->where('target_audience->user_id', $userId)
                        ->orWhere(function ($q) {
                            $q->whereNull('target_audience')
                                ->orWhere('target_audience', '[]')
                                ->orWhere('target_audience', '{}');
                        });
                })->count(),
        ];

        return response()->json(
            $this->paginatorToArray($broadcasts, fn(Broadcast $b) => $this->formatBroadcast($b)) + [
                'counts' => $counts,
                'filter' => $filter,
            ]
        );
    }

    /**
     * Формат строки рассылки в per-user табе.
     *
     * message — целиком (фронт сам ограничивает через line-clamp + title).
     * progress — accessor Broadcast::getProgressAttribute() (0–100).
     * audience_label — accessor, персональная рассылка → «Юзер: …».
     */
    private function formatBroadcast(Broadcast $b): array
    {
        return [
            'id'                => $b->id,
            'title'             => $b->title,
            'message'           => $b->message,
            'type'              => $b->type,
            'status'            => $b->status,
            'progress'          => $b->progress,
            'sent_count'        => $b->sent_count,
            'failed_count'      => $b->failed_count,
            'total_recipients'  => $b->total_recipients,
            'scheduled_at'      => $b->scheduled_at?->toIso8601String(),
            'sent_at'           => $b->sent_at?->toIso8601String(),
            'started_at'        => $b->started_at?->toIso8601String(),
            'created_at'        => $b->created_at?->toIso8601String(),
            'audience_label'    => $b->audience_label,
            'admin'             => $b->admin ? ['id' => $b->admin->id, 'name' => $b->admin->name] : null,
        ];
    }

    // ============================================
    // ЛОГИ АДМИНОВ по юзеру (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/admin-logs?page=2
     *
     * ВСЕ действия админов над юзером — без фильтра по action
     * (в отличие от ban-history, где только ban-семейство).
     * Источник: AdminLog, где:
     *   - loggable_type = User AND loggable_id = userId, ИЛИ
     *   - participants (jsonb) содержит userId (например, refund
     *     транзакции юзера пишет participants: [userId]).
     */
    public function adminLogs(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $logs = AdminLog::where(function ($query) use ($userId) {
            $query->where(function ($q) use ($userId) {
                $q->where('loggable_type', User::class)
                    ->where('loggable_id', $userId);
            })
                ->orWhere(function ($q) use ($userId) {
                    $q->whereNotNull('participants')
                        ->whereRaw("participants::jsonb @> ?", [json_encode([$userId])]);
                });
        })
            ->with('admin:id,name,email')
            ->latest()
            ->paginate($request->perPage(5), ['*'], 'page', $request->page());

        return response()->json($this->paginatorToArray($logs, fn(AdminLog $log) => [
            'id'         => $log->id,
            'action'     => $log->action,
            'before'     => $log->before,
            'after'      => $log->after,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
            // Метаданные для рендера таймлайна (зеркало blade-эталона
            // \App\Support\AdminLogMeta::get($action) — title/icon/iconColor/badge).
            'meta'       => AdminLogMeta::get($log->action),
            'admin'      => $log->admin ? [
                'id'   => $log->admin->id,
                'name' => $log->admin->name,
                'email' => $log->admin->email,
            ] : null,
        ]));
    }

    // ============================================
    // ИСТОРИЯ ВХОДОВ юзера (admin only)
    // ============================================

    /**
     * GET /api/admin/users/{user}/auth-logs?outcome=all&page=2
     *
     * user_auth_logs юзера, включая НЕУДАЧНЫЕ попытки входа
     * (антифрод-контекст). user_agent обрезаем до 200 символов —
     * боты шлют огромные UA, в таблице они нечитаемы.
     */
    public function authLogs(UserAuthLogsRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $outcome = $request->outcome('all');

        $logs = UserAuthLog::query()
            ->where('user_id', $userId)
            ->when($outcome === 'success', fn($q) => $q->where('is_successful', true))
            ->when($outcome === 'failed', fn($q) => $q->where('is_successful', false))
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->perPage(5), ['*'], 'page', $request->page());

        $counts = UserAuthLog::where('user_id', $userId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN is_successful = true THEN 1 ELSE 0 END) as success,
                SUM(CASE WHEN is_successful = false THEN 1 ELSE 0 END) as failed
            ")
            ->first();

        return response()->json(
            $this->paginatorToArray($logs, fn(UserAuthLog $l) => [
                'id'           => $l->id,
                'ip_address'   => $l->ip_address,
                'user_agent'   => Str::limit($l->user_agent, 200),
                'device_os'    => $l->device_os,
                'device_type'  => $l->device_type,
                'is_successful' => (bool) $l->is_successful,
                'created_at'   => $l->created_at?->toIso8601String(),
            ]) + [
                'counts' => [
                    'total'   => (int) ($counts->total ?? 0),
                    'success' => (int) ($counts->success ?? 0),
                    'failed'  => (int) ($counts->failed ?? 0),
                ],
                'outcome' => $outcome,
            ]
        );
    }
}
