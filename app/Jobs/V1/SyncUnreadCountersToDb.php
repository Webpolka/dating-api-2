<?php

namespace App\Jobs\V1;

use App\Models\ChatParticipant;
use App\Services\V1\UnreadCounterService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class SyncUnreadCountersToDb implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $timeout = 60;
    public $tries = 3;
    public $backoff = 30;

    /**
     * Префикс для отслеживания синхронизированных юзеров.
     * Чтобы не синхронизировать одних и тех же юзеров каждую минуту.
     */
    private const SYNCED_USERS_KEY = 'unread:synced_users';

    public function __construct()
    {
        // Нет входных данных — Job работает с Redis
    }

    /**
     * Execute the job.
     */
    public function handle(UnreadCounterService $service): void
    {
        Log::info('🔄 [Job] SyncUnreadCountersToDb started');

        $startTime = microtime(true);

        try {
            // 1. Находим всех юзеров у кого есть счётчики в Redis
            $userIds = $this->getUsersWithCounters();

            if (empty($userIds)) {
                Log::debug('✅ [Job] SyncUnreadCountersToDb — no users to sync');
                return;
            }

            $synced = 0;
            $errors = 0;

            foreach ($userIds as $userId) {
                try {
                    $this->syncUserCounters($service, (int) $userId);
                    $synced++;
                } catch (\Throwable $e) {
                    $errors++;
                    Log::warning('⚠️ [Job] Failed to sync user', [
                        'user_id' => $userId,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            $durationMs = round((microtime(true) - $startTime) * 1000, 2);

            Log::info('✅ [Job] SyncUnreadCountersToDb completed', [
                'users_total' => count($userIds),
                'synced' => $synced,
                'errors' => $errors,
                'duration_ms' => $durationMs,
            ]);
        } catch (\Throwable $e) {
            Log::error('❌ [Job] SyncUnreadCountersToDb FAILED', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);
            throw $e;
        }
    }

    /**
     * Найти всех юзеров у кого есть счётчики в Redis.
     * Ищем по ключам user:unread:total:*
     *
     * FIX (В4): раньше — Redis::keys('user:unread:total:*'). KEYS сканирует
     * ВЕСЬ keyspace одним махом и блокирует однопоточный Redis: пока идёт
     * выборка, все остальные команды (онлайн-статусы, кэш ленты, rate-limiter,
     * Horizon) стоят в очереди. На cron-запуске каждую минуту — это
     * гарантированные микрофризы всего приложения. Заменено на SCAN:
     * инкрементальный курсор, обрабатывает keyspace маленькими пачками.
     *
     * Формат ответа Laravel-обёртки scan(): [cursor, keys] | false.
     * Совместимо с phpredis и predis.
     */
    private function getUsersWithCounters(): array
    {
        $pattern = 'user:unread:total:*';
        $userIds = [];

        try {
            /** @var \Illuminate\Contracts\Redis\Connection $connection */
            $connection = \Illuminate\Support\Facades\Redis::connection();

            $cursor = 0;

            do {
                // ВАЖНО: второй аргумент — МАССИВ опций (сигнатура
                // PhpRedisConnection::scan($cursor, $options)). Передача
                // строки паттерна напрямую не применяется — scan вернул бы
                // все ключи keyspace.
                $result = $connection->scan($cursor, [
                    'match' => $pattern,
                    'count' => 500,
                ]);

                if ($result === false) {
                    break; // курсор дошёл до конца — скан завершён
                }

                [$cursor, $keys] = $result;

                foreach ((array) $keys as $key) {
                    // Извлекаем user_id из ключа (учитывая префикс)
                    // Формат: dating-app-database-user:unread:total:{id}
                    if (preg_match('/user:unread:total:(\d+)$/', $key, $matches)) {
                        $userIds[] = (int) $matches[1];
                    }
                }
            } while ((int) $cursor !== 0);
        } catch (\Throwable $e) {
            Log::warning('⚠️ [Job] SyncUnreadCountersToDb: Redis SCAN failed', [
                'error' => $e->getMessage(),
            ]);
            return [];
        }

        return array_unique($userIds);
    }

    /**
     * Синхронизировать счётчики одного юзера.
     */
    private function syncUserCounters(UnreadCounterService $service, int $userId): void
    {
        // 1. Получаем snapshot из Redis
        $chatCounts = $service->getSnapshotForDbSync($userId);

        if (empty($chatCounts)) {
            return;
        }

        // 2. Bulk UPDATE в БД через CASE WHEN
        // Это позволяет обновить все чаты одним SQL-запросом
        $cases = [];
        $bindings = [];
        $chatIds = [];

        foreach ($chatCounts as $chatId => $count) {
            $cases[] = "WHEN chat_id = ? THEN ?";
            $bindings[] = $chatId;
            $bindings[] = $count;
            $chatIds[] = $chatId;
        }

        if (empty($chatIds)) {
            return;
        }

        // Строим SQL: UPDATE chat_participants SET unread_count = CASE WHEN chat_id = 1 THEN 5 WHEN chat_id = 2 THEN 3 END WHERE user_id = ? AND chat_id IN (1, 2)
        $sql = 'UPDATE chat_participants SET unread_count = CASE ' . implode(' ', $cases) . ' END';
        $sql .= ' WHERE user_id = ? AND chat_id IN (' . implode(',', array_fill(0, count($chatIds), '?')) . ')';

        $bindings[] = $userId;
        foreach ($chatIds as $chatId) {
            $bindings[] = $chatId;
        }

        DB::update($sql, $bindings);

        Log::debug('🔄 [Job] Synced user counters', [
            'user_id' => $userId,
            'chats_count' => count($chatCounts),
        ]);
    }

    /**
     * Вызывается когда Job окончательно упал.
     */
    public function failed(\Throwable $exception): void
    {
        Log::critical('🚨 [Job] SyncUnreadCountersToDb FAILED permanently', [
            'error' => $exception->getMessage(),
        ]);
    }
}
