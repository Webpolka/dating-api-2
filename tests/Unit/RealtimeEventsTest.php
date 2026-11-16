<?php

namespace Tests\Unit;

use App\Events\V1\BroadcastStatusUpdated;
use App\Events\V1\ChatMessageReceived;
use App\Events\V1\NewMatch;
use App\Events\V1\NewMessage;
use App\Events\V1\SupportChatArchived;
use App\Events\V1\SupportChatRead;
use App\Events\V1\SupportChatUnarchived;
use App\Events\V1\SupportMessageReceived;
use App\Events\V1\TransactionRefunded;
use App\Events\V1\TransactionRefundFailed;
use App\Events\V1\TransactionSynced;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use ReflectionClass;
use Tests\TestCase;

/**
 * ПАКЕТ 5 (рекомендация №1 аудита Шага 1): realtime-события уходят в ОЧЕРЕДЬ
 * broadcasts (supervisor-default в Horizon), а не синхронно в Reverb внутри
 * HTTP-запроса пользователя.
 *
 * Контракт (обновлено под Laravel 13: контракт ShouldBroadcastAfterCommit
 * существовал только в Laravel 11–12 и в 13-й удалён):
 *   - каждый чат-эвент реализует ShouldBroadcast (ушёл в очередь) И
 *     объявляет public bool $afterCommit = true — в Laravel 13 именно
 *     свойство включает after-commit (job BroadcastEvent читает
 *     $event->afterCommit → пуш ставится в очередь ТОЛЬКО после коммита
 *     транзакции — получатель не видит пуш раньше фиксации в БД);
 *   - НИ один не остаётся ShouldBroadcastNow (синхронный поход в Reverb
 *     из запроса — главный риск подвисания API чатов по аудиту);
 *   - broadcastQueue() = 'broadcasts' — очередь, которую слушает
 *     supervisor-default (config/horizon.php), отдельная от payments/heavy.
 */
class RealtimeEventsTest extends TestCase
{
    private const CHAT_EVENTS = [
        NewMessage::class,
        NewMatch::class,
        ChatMessageReceived::class,
        SupportMessageReceived::class,
        SupportChatRead::class,
        SupportChatArchived::class,
        SupportChatUnarchived::class,
    ];

    public function test_chat_events_are_queued_after_commit(): void
    {
        foreach (self::CHAT_EVENTS as $eventClass) {
            $interfaces = class_implements($eventClass);

            $this->assertContains(
                ShouldBroadcast::class,
                $interfaces !== false ? $interfaces : [],
                "{$eventClass} должен реализовывать ShouldBroadcast",
            );

            $this->assertNotContains(
                ShouldBroadcastNow::class,
                $interfaces !== false ? $interfaces : [],
                "{$eventClass} не должен остаться на ShouldBroadcastNow (синхронный Reverb-вызов в запросе)",
            );

            // Laravel 13: after-commit включается свойством события
            // (BroadcastEvent::$afterCommit = $event->afterCommit).
            $reflection = new ReflectionClass($eventClass);

            $this->assertTrue(
                $reflection->hasProperty('afterCommit'),
                "{$eventClass} должен объявлять \$afterCommit (Laravel 13: after-commit через свойство)",
            );

            $defaults = $reflection->getDefaultProperties();

            $this->assertSame(
                true,
                $defaults['afterCommit'] ?? null,
                "{$eventClass}: \$afterCommit должен быть public bool = true",
            );
        }
    }

    public function test_chat_events_use_broadcasts_queue(): void
    {
        foreach (self::CHAT_EVENTS as $eventClass) {
            $reflection = new ReflectionClass($eventClass);

            $this->assertTrue(
                $reflection->hasMethod('broadcastQueue'),
                "{$eventClass} должен задавать broadcastQueue()",
            );

            /** @var ShouldBroadcast $instance */
            $instance = $reflection->newInstanceWithoutConstructor();

            $this->assertSame(
                'broadcasts',
                $instance->broadcastQueue(),
                "{$eventClass} должен уйти в очередь broadcasts (supervisor-default Horizon)",
            );
        }
    }

    public function test_payment_events_remain_queued_not_sync(): void
    {
        // Платёжные события и так были на очереди (ShouldBroadcast) — Пакет 5
        // их не трогал; проверяем, что их не перетащило в синхронный режим.
        $paymentEvents = [
            TransactionRefunded::class,
            TransactionRefundFailed::class,
            TransactionSynced::class,
            BroadcastStatusUpdated::class,
        ];

        foreach ($paymentEvents as $eventClass) {
            $interfaces = class_implements($eventClass);

            $this->assertNotContains(
                ShouldBroadcastNow::class,
                $interfaces !== false ? $interfaces : [],
                "{$eventClass} не должен быть ShouldBroadcastNow",
            );
        }
    }
}
