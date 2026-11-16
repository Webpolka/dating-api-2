<?php

namespace App\Events\V1;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Realtime-уведомение админки: тикет поддержки прочитан одним из staff.
 *
 * Стреляется из SupportChatController::markAsRead().
 * toOthers() — не получает отправитель (он и так знает что прочитал).
 *
 * Канал: private-admin.support (все staff).
 * Фронт (useSupportChatsList) слушает .chat.read →
 *   обновляет list item: убирает красный бейдж unread, пишет «Прочитал {name}».
 *
 * Пакет 5: уходит в очередь broadcasts (после коммита), не в запросе.
 */
class SupportChatRead implements ShouldBroadcast
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
        public int $readById,
        public string $readByName,
        public string $readAt
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.support'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'chat.read';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->chatId,
            'read_by_id' => $this->readById,
            'read_by_name' => $this->readByName,
            'read_at' => $this->readAt,
        ];
    }
}
