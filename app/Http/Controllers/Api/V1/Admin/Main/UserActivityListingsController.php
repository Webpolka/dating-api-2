<?php

namespace App\Http\Controllers\Api\V1\Admin\Main;

use App\Enums\V1\UserEventType;
use App\Http\Requests\Api\V1\Admin\Main\UserDatingRequest;
use App\Http\Requests\Api\V1\Admin\Main\UserListRequest;
use App\Http\Resources\Api\V1\Admin\AdminUserBadgeResource;
use App\Models\ChatParticipant;
use App\Models\Swipe;
use App\Models\UserEvent;
use App\Models\UserMatch;
use Illuminate\Http\JsonResponse;

/**
 * Пер-юзер листинги: АКТИВНОСТЬ (табы «Знакомства», «Чаты», «События»
 * страницы /users/{id}).
 *
 * ПАКЕТ 6: выделен из монолита UserListingsController (1477 строк).
 * URL и форматы ответов — 1:1 с прежним кодом.
 */
class UserActivityListingsController extends UserTabListingController
{
    // ============================================
    // МОДЕРАЦИЯ: знакомства (свайпы + мэтчи юзера)
    // ============================================

    /**
     * GET /api/admin/users/{user}/dating?swipes_page=1&matches_page=2
     *
     * Две независимые пагинации (паттерн reports made/received):
     *  - swipes:  свайпы, сделанные юзером (+ counts по типу)
     *  - matches: мэтчи с участием юзера (обе стороны)
     */
    public function dating(UserDatingRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $badgeUser = $this->badgeUser();

        $swipes = Swipe::with(['targetUser' => $badgeUser])
            ->where('user_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'swipes_page', $request->swipesPage());

        $matches = UserMatch::with(['user1' => $badgeUser, 'user2' => $badgeUser])
            ->where(function ($q) use ($userId) {
                $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
            })
            ->latest('created_at')
            ->latest('id')
            ->paginate(5, ['*'], 'matches_page', $request->matchesPage());

        $swipeCounts = Swipe::where('user_id', $userId)
            ->selectRaw("
                COUNT(*) as total,
                SUM(CASE WHEN type = 'like' THEN 1 ELSE 0 END) as likes,
                SUM(CASE WHEN type = 'dislike' THEN 1 ELSE 0 END) as dislikes,
                SUM(CASE WHEN type = 'superlike' THEN 1 ELSE 0 END) as superlikes
            ")
            ->first();

        return response()->json([
            'swipes'  => $this->paginatorToArray($swipes, fn(Swipe $s) => $this->formatSwipe($s)),
            'matches' => $this->paginatorToArray($matches, fn(UserMatch $m) => $this->formatMatch($m)),
            'counts'  => [
                'swipes'      => (int) ($swipeCounts->total ?? 0),
                'likes'       => (int) ($swipeCounts->likes ?? 0),
                'dislikes'    => (int) ($swipeCounts->dislikes ?? 0),
                'superlikes'  => (int) ($swipeCounts->superlikes ?? 0),
                'matches'     => UserMatch::where(function ($q) use ($userId) {
                    $q->where('user1_id', $userId)->orWhere('user2_id', $userId);
                })->count(),
            ],
        ]);
    }

    /** Формат 1:1 с DatingController::formatSwipe(), но без автора — автор это сам юзер страницы. */
    private function formatSwipe(Swipe $s): array
    {
        return [
            'id'         => $s->id,
            'type'       => $s->type,
            'rewinded'   => (bool) $s->rewinded_at,
            'created_at' => $s->created_at?->toIso8601String(),
            'targetUser' => $s->targetUser ? AdminUserBadgeResource::nullable($s->targetUser) : null,
        ];
    }

    /** Формат 1:1 с DatingController::formatMatch(). */
    private function formatMatch(UserMatch $m): array
    {
        return [
            'id'           => $m->id,
            'status'       => $m->status,
            'unmatched_by' => $m->unmatched_by,
            'unmatched_at' => $m->unmatched_at?->toIso8601String(),
            'created_at'   => $m->created_at?->toIso8601String(),
            'user1'        => $m->user1 ? AdminUserBadgeResource::nullable($m->user1) : null,
            'user2'        => $m->user2 ? AdminUserBadgeResource::nullable($m->user2) : null,
        ];
    }

    // ============================================
    // ЧАТЫ юзера
    // ============================================

    /**
     * GET /api/admin/users/{user}/chats?page=2
     *
     * Private-чаты с участием юзера. Действия (lock/unlock, чтение сообщений) —
     * через глобальный раздел /communication/chats.
     *
     * Формат НЕ совпадает 1:1 с глобальным ChatController::formatChatListItem():
     * в per-user табе нужен «собеседник» (partner ≠ текущий юзер), а не оба
     * участника, плюс participant-поля юзера (unread/archive/mute/block) для
     * бейджей строки. Сортировка по last_read_at — как в blade-эталоне
     * (недавно читанные наверх).
     */
    public function chats(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $avatarQuery = fn($q) => $q->select([
            'id',
            'user_id',
            'is_primary',
            'status',
            'path_thumb',
            'path_medium',
        ])
            ->orderByDesc('is_primary')
            ->orderByDesc('id');
            // FIX: убран ->limit(1) — ловушка constrained eager load
            // (фото получал только первый чат страницы)

        // ChatParticipant как точка входа — чтобы сортировать по last_read_at
        // участника (как в blade: ->latest('last_read_at')) и иметь под рукой
        // его же participant-поля (unread_count, is_hidden, ...).
        $participants = ChatParticipant::where('user_id', $userId)
            ->with([
                'chat' => fn($q) => $q->select('id', 'type', 'last_message_at', 'is_locked')
                    ->with([
                        'participants' => fn($pq) => $pq->select([
                            'id', 'chat_id', 'user_id',
                            'is_blocked', 'is_muted', 'is_hidden', 'unread_count',
                        ])->with([
                            'user' => fn($uq) => $uq->withTrashed()->with(['photos' => $avatarQuery])
                                ->select(['id', 'name', 'email', 'status', 'premium_expires_at', 'vip_expires_at', 'last_seen', 'deleted_at']),
                        ]),
                        'latestMessage' => fn($mq) => $mq->select([
                            'messages.id', 'messages.chat_id', 'messages.type',
                            'messages.body', 'messages.created_at',
                        ]),
                    ]),
            ])
            ->latest('last_read_at')
            ->latest('id')
            ->paginate($request->perPage(5), ['*'], 'page', $request->page());

        return response()->json(
            $this->paginatorToArray($participants, fn(ChatParticipant $p) => $this->formatChatParticipantRow($p, $userId))
        );
    }

    /**
     * Формат строки чата в per-user табе.
     *
     * Берём participant текущего юзера (для его unread/archive/mute/block),
     * chat (id, type, last_message_at, is_locked), собеседника (partner —
     * другой участник ≠ userId) и последнее сообщение.
     */
    private function formatChatParticipantRow(ChatParticipant $participant, int $userId): array
    {
        $chat = $participant->chat;

        // null-чаты не должны случаться ( FK), но защищаемся.
        if (!$chat) {
            return [
                'participant_id'    => $participant->id,
                'chat'              => null,
                'partner'           => null,
                'participant_state' => $this->participantState($participant),
            ];
        }

        // Собеседник — другой участник чата (user_id !== текущий юзер).
        $partnerParticipant = $chat->participants->firstWhere('user_id', '!==', $userId);
        $partner = $partnerParticipant?->user;

        $lastMsg = $chat->latestMessage;

        return [
            'participant_id'    => $participant->id,
            'chat' => [
                'id'              => $chat->id,
                'type'            => $chat->type,
                'is_locked'       => (bool) $chat->is_locked,
                'last_message_at' => $chat->last_message_at?->toIso8601String(),
                'last_message'    => $lastMsg ? [
                    'id'         => $lastMsg->id,
                    'type'       => $lastMsg->type,
                    'body'       => $lastMsg->body,
                    'created_at' => $lastMsg->created_at?->toIso8601String(),
                ] : null,
            ],
            // Собеседник (как AdminUserBadgeResource для совместимости с UserBadge).
            'partner' => $partner ? AdminUserBadgeResource::nullable($partner) : null,
            // Состояние участника-юзера для бейджей строки.
            'participant_state' => $this->participantState($participant),
        ];
    }

    /** Вынести participant-поля в отдельный блок (unread/archive/mute/block). */
    private function participantState(ChatParticipant $p): array
    {
        return [
            'unread_count' => (int) $p->unread_count,
            'is_hidden'    => (bool) $p->is_hidden,
            'is_muted'     => (bool) $p->is_muted,
            'is_blocked'   => (bool) $p->is_blocked,
            'last_read_at' => $p->last_read_at?->toIso8601String(),
        ];
    }

    // ============================================
    // ЛЕНТА СОБЫТИЙ юзера
    // ============================================

    /**
     * GET /api/admin/users/{user}/events?page=2
     *
     * user_events — лента активности (photo_updated, birthday,
     * status_changed, vip_purchased...). properties — jsonb.
     *
     * meta (label/icon/color) берётся из App\Enums\V1\UserEventType —
     * enum один источник истины (зеркало blade-эталона, который вызывал
     * UserEventType::tryFrom($type)->icon()/color()/label()). Для типов,
     * отсутствующих в enum, — fallback: иконка 'activity', нейтральный
     * цвет, заголовок из ucfirst(str_replace('_', ' ', $type)).
     */
    public function events(UserListRequest $request, int $userId): JsonResponse
    {
        if ($notFound = $this->userNotFoundResponse($userId)) {
            return $notFound;
        }

        $events = UserEvent::query()
            ->where('user_id', $userId)
            ->latest('created_at')
            ->latest('id')
            ->paginate($request->perPage(20), ['*'], 'page', $request->page());

        return response()->json($this->paginatorToArray($events, fn(UserEvent $e) => [
            'id'         => $e->id,
            'type'       => $e->type,
            'properties' => $e->properties,
            'created_at' => $e->created_at?->toIso8601String(),
            // meta из enum — title/icon/color для таймлайна (зеркало blade-эталона).
            'meta'       => $this->eventMeta($e->type),
        ]));
    }

    /**
     * Метаданные события из UserEventType enum.
     *
     * Для неизвестных типов (нет в enum) — fallback, как в blade-эталоне:
     *   - title  → ucfirst(str_replace('_', ' ', $type))
     *   - icon   → 'activity'
     *   - color  → 'bg-primary/10 text-primary'
     */
    private function eventMeta(string $type): array
    {
        $enum = UserEventType::tryFrom($type);

        if ($enum === null) {
            return [
                'title' => ucfirst(str_replace('_', ' ', $type)),
                'icon'  => 'activity',
                'color' => 'bg-primary/10 text-primary',
            ];
        }

        return [
            'title' => $enum->label(),
            'icon'  => $enum->icon(),
            'color' => $enum->color(),
        ];
    }
}
