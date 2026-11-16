<?php

namespace App\Events\V1;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Str;

/**
 * Realtime-уведомение админки о новом сообщении в чате поддержки.
 *
 * Стреляется из MessageObserver::created() когда chat type='support'.
 *
 * Канал: private-admin.support (все staff).
 * Фронт (useSupportChatsList) слушает .message.received →
 *   invalidateQueries → refetch списка (unread, last message, сортировка).
 *
 * Пакет 5 (рекомендация №1 аудита): уходит в очередь broadcasts ПОСЛЕ
 * коммита транзакции ($afterCommit=true, Laravel 13) — Latency API-ответа
 * больше не зависит от Reverb, фронт при refetch видит сообщение.
 */
class SupportMessageReceived implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    // В Laravel 13 это свойство — ЕДИНСТВЕННЫЙ способ включить after-commit:
    // контракт ShouldBroadcastAfterCommit (11–12) удалён, job BroadcastEvent
    // читает $event->afterCommit напрямую.
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
        public int $senderId,
        public string $senderName,
        public bool $senderIsStaff,
        public string $messageType,
        public ?string $messageBody,
        public ?string $createdAt
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('admin.support'),
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
            'sender_is_staff' => $this->senderIsStaff,
            'message_type' => $this->messageType,
            'message_body' => $this->messageBody ? Str::limit($this->messageBody, 120) : null,
            'created_at' => $this->createdAt,
        ];
    }
}
