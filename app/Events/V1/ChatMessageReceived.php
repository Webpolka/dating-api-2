<?php

namespace App\Events\V1;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Realtime-уведомение админки: новое сообщение в private-чате юзеров.
 *
 * Стреляется из MessageObserver::created() когда chat type='private'
 * (т.е. user↔user чат, не support).
 *
 * Канал: private-admin.chats (все staff с доступом к /admin/chats).
 * Фронт (useChatsList) слушает .message.received →
 *   invalidateQueries → refetch списка (last_message, last_message_at, сортировка).
 *
 * Пакет 5 (рекомендация №1 аудита): уходит в очередь broadcasts ПОСЛЕ
 * коммита транзакции ($afterCommit=true, Laravel 13) — Latency API-ответа
 * больше не зависит от Reverb, фронт при refetch видит сообщение.
 *
 * ВАЖНО про нагрузку: этот event стреляет на КАЖДОЕ сообщение в КАЖДОМ
 * private чате. Если 1000 чатов активно и каждый шлёт 1 msg/sec —
 * 1000 broadcasts/sec. Reverb это выдержит (он designed для этого).
 * Если станет узким местом — можно:
 *   - throttle broadcasts (не стрелять чаще 1/sec на chat)
 *   - batching (группировать несколько сообщений в один event)
 * Пока не нужно — оставляем simple.
 */
class ChatMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * FIX (Пакет 5, рекомендация №1 аудита Шага 1): realtime уходит в ОЧЕРЕДЬ
     * broadcasts, а не синхронно в Reverb внутри HTTP-запроса пользователя.
     *
     * Раньше (ShouldBroadcastNow) каждый POST сообщения/действие админа ходило
     * в Reverb ПРЯМО из запроса: латентность ответа API напрямую зависела от
     * здоровья WebSocket-сервера (Reverb тупит — чаты «висят»).
     *
     * ShouldBroadcast + $afterCommit=true (Laravel 13): job BroadcastEvent
     * ставится в очередь ТОЛЬКО после коммита транзакции — получатель не увидит пуш раньше,
     * чем сообщение реально зафиксировано в БД (раньше пуш мог прийти
     * до коммита: фронт делал refetch и не видел сообщения).
     *
     * Очередь broadcasts слушает supervisor-default (Horizon).
     */
    public function broadcastQueue(): string
    {
        return 'broadcasts';
    }

    public bool $afterCommit = true;

    public function __construct(
        public int $chatId,
        public int $senderId,
        public string $senderName,
        public string $messageType,
        public ?string $messageBody,
        public ?string $createdAt
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.chats'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.received';
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->chatId,
            'sender_id' => $this->senderId,
            'sender_name' => $this->senderName,
            'message_type' => $this->messageType,
            // Snippet до 120 символов — для preview в списке чатов.
            // Полное body фронт получит через refetch detail.
            'message_body' => $this->messageBody ? Str::limit($this->messageBody, 120) : null,
            'created_at' => $this->createdAt,
        ];
    }
}
