<?php

namespace App\Events\V1;

use App\Models\Message;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class NewMessage implements ShouldBroadcast
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
        public Message $message
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('chat.'.$this->message->chat_id),
        ];
    }

    public function broadcastAs(): string
    {
        return 'message.new';
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->message->id,
            'sender_id' => $this->message->sender_id,
            'type' => $this->message->type,
            'body' => $this->message->body,
            'attachment_url' => $this->message->attachment_url,
            'gift_id' => $this->message->gift_id,
            'status' => $this->message->status,
            'created_at' => $this->message->created_at?->toIso8601String(),
        ];
    }
}
