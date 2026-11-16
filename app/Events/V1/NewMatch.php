<?php

namespace App\Events\V1;

use App\Models\UserMatch;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class NewMatch implements ShouldBroadcast
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
        public UserMatch $match,
        public int $recipientId
    ) {
        Log::info('🎬 [Event] NewMatch constructed', [
            'match_id' => $this->match->id,
            'recipient_id' => $this->recipientId,
        ]);
    }

    public function broadcastOn(): array
    {
        $channel = 'private-user.'.$this->recipientId;
        Log::info('📡 [Event] NewMatch::broadcastOn()', [
            'match_id' => $this->match->id,
            'channel' => $channel,
        ]);

        return [
            new PrivateChannel('user.'.$this->recipientId),
        ];
    }

    public function broadcastAs(): string
    {
        return 'match.new';
    }

    public function broadcastWith(): array
    {
        $partnerId = $this->match->user1_id === $this->recipientId
            ? $this->match->user2_id
            : $this->match->user1_id;

        $payload = [
            'match_id' => $this->match->id,
            'partner_id' => $partnerId,
            'created_at' => $this->match->created_at?->toIso8601String(),
        ];

        Log::info('📦 [Event] NewMatch::broadcastWith() payload prepared', [
            'match_id' => $this->match->id,
            'payload' => $payload,
        ]);

        return $payload;
    }
}
