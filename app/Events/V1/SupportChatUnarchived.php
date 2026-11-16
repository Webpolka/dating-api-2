<?php

namespace App\Events\V1;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Realtime-уведомение админки: тикет возвращён из архива.
 *
 * Стреляется из SupportChatController::unarchive().
 * Также из sendMessage() при auto-unarchive (когда админ ответил в архивный тикет).
 * toOthers() — не получает отправитель.
 *
 * Канал: private-admin.support (все staff).
 * Фронт слушает .chat.unarchived →
 *   обновляет is_archived=false в list item
 *   если filter='archived' — убирает тикет из списка
 */
class SupportChatUnarchived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * Laravel 13: контракт ShouldBroadcastAfterCommit (существовал только
     * в 11–12) удалён; after-commit теперь включается этим свойством —
     * job BroadcastEvent читает $event->afterCommit напрямую.
     */
    public bool $afterCommit = true;

    /**
     * FIX (Пакет 5, рекомендация №1 аудита Шага 1): realtime уходит в ОЧЕРЕДЬ
     * broadcasts, а не синхронно в Reverb внутри HTTP-запроса пользователя.
     *
     * Раньше (ShouldBroadcastNow) каждый POST сообщения/действие админа ходило
     * в Reverb ПРЯМО из запроса: латентность ответа API напрямую зависела от
     * здоровья WebSocket-сервера (Reverb тупит — чаты «висят»).
     *
     * ShouldBroadcast + $afterCommit=true: job BroadcastEvent ставится в очередь
     * ТОЛЬКО после коммита транзакции — получатель не увидит пуш раньше,
     * чем сообщение реально зафиксировано в БД (раньше пуш мог прийти
     * до коммита: фронт делал refetch и не видел сообщения).
     *
     * Очередь broadcasts слушает supervisor-default (Horizon).
     */
    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public function __construct(
        public int $chatId,
        public int $unarchivedById,
        public string $unarchivedByName,
        public string $unarchivedAt,
        public ?string $reason = null
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.support'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.unarchived';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->chatId,
            'unarchived_by_id' => $this->unarchivedById,
            'unarchived_by_name' => $this->unarchivedByName,
            'unarchived_at' => $this->unarchivedAt,
            'reason' => $this->reason,
        ];
    }
}
